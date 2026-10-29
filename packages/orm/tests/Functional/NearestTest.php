<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Exception\VectorDimensionMismatchException;
use Prism\Orm\Repository\EntityRepository;
use Prism\Orm\Tests\Functional\VectorFixtures\Passage;
use Prism\Orm\Tests\Functional\VectorFixtures\Passage_;
use Prism\Orm\Tests\Functional\VectorFixtures\PassageDistance;
use Prism\Orm\Vector\Distance;
use Prism\Orm\Vector\ScoredResult;
use Prism\Orm\Vector\Vector;

/**
 * nearest() et distanceTo() sur MariaDB, PostgreSQL et SQLite (RG-09.4, ADR 0017) : classement
 * par la base, filtres et critères avant lui, score, vecteurs lus sans requête de plus ; forme du
 * SQL qui garde l'index ; relance exacte sous MariaDB quand l'index rend moins que la limite.
 */
final class NearestTest extends MappingSchemaTestCase
{
    private TestLogger $logger;

    private EntityManager $em;

    /** @var EntityRepository<Passage> */
    private EntityRepository $passages;

    protected static function entities(): array
    {
        return [Passage::class];
    }

    protected function setUp(): void
    {
        if (null !== TestDatabase::url() && !TestDatabase::connection()->getPlatform()->supportsVectors()) {
            self::markTestSkipped('MySQL has no vector type.');
        }
        parent::setUp();
        $this->logger = new TestLogger();
        $this->connection->close();
        $this->connection = TestDatabase::connection(new Configuration($this->logger));
        if (':memory:' === $this->connection->getDatabase()) {
            $this->migrateTo($this->target());
        }
        $this->em = new EntityManager($this->connection);
        $this->passages = $this->em->getRepository(Passage::class);

        // autour de l'axe x : invoice 1 est sur l'axe, puis s'en écarte ; stock est sur l'axe y
        foreach ([
            ['invoice', 'on the axis', [1.0, 0.0, 0.0]],
            ['invoice', 'close', [0.9, 0.1, 0.0]],
            ['invoice', 'diagonal', [0.5, 0.5, 0.0]],
            ['invoice', 'far', [0.1, 0.9, 0.0]],
            ['stock', 'stock close', [0.95, 0.05, 0.0]],
            ['stock', 'stock y', [0.0, 1.0, 0.0]],
        ] as [$topic, $title, $vector]) {
            $this->em->persist(new Passage($topic, $title, new Vector($vector)));
        }
        $this->em->flush();
        $this->em->clear();
        $this->logger->records = [];
    }

    /**
     * @return list<string>
     *
     * @phpstan-impure
     */
    private function selects(): array
    {
        return array_values(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
    }

    /**
     * @param list<ScoredResult<Passage>> $results
     *
     * @return list<string>
     */
    private static function titles(array $results): array
    {
        return array_map(static fn (ScoredResult $r): string => $r->entity->title, $results);
    }

    public function testNearestRanksByDistanceWithAScore(): void
    {
        $results = $this->passages->nearest('embedding', new Vector([1.0, 0.0, 0.0]), limit: 3);

        self::assertSame(['on the axis', 'stock close', 'close'], self::titles($results));
        self::assertEqualsWithDelta(0.0, $results[0]->distance, 1e-6);
        self::assertEqualsWithDelta(1.0, $results[0]->score, 1e-6, 'cosine: 1 − d / 2');
        self::assertGreaterThan($results[2]->score, $results[1]->score);
        // RG-09.6 : les vecteurs sont lus avec les entités, sans requête de plus
        self::assertFalse(new \ReflectionClass($results[0]->entity->embedding)->isUninitializedLazyObject($results[0]->entity->embedding));
        self::assertCount(1, $this->selects());
    }

    // RG-09.4 : les critères filtrent avant le classement ; un vecteur en liste est accepté
    public function testCriteriaFilterBeforeRanking(): void
    {
        $results = $this->passages->nearest('embedding', [1.0, 0.0, 0.0], limit: 2, criteria: ['topic' => 'stock']);

        self::assertSame(['stock close', 'stock y'], self::titles($results));
    }

    // RG-08.14 : une ligne supprimée logiquement n'est jamais classée
    public function testSoftDeletedRowsAreExcluded(): void
    {
        $axis = $this->passages->findOneBy(['title' => 'on the axis']);
        self::assertNotNull($axis);
        $this->em->remove($axis);
        $this->em->flush();

        self::assertSame(['stock close', 'close'], self::titles($this->passages->nearest('embedding', [1.0, 0.0, 0.0], limit: 2)));
    }

    // ADR 0017 : sous MariaDB, moins de lignes que la limite fait relancer le calcul exact
    public function testFewerRowsThanTheLimit(): void
    {
        $results = $this->passages->nearest('embedding', [1.0, 0.0, 0.0], limit: 5, criteria: ['topic' => 'stock']);

        self::assertSame(['stock close', 'stock y'], self::titles($results));
        $selects = $this->selects();
        if ('MariaDB' === $this->connection->getPlatform()->getName()) {
            self::assertCount(2, $selects);
            self::assertStringContainsString(' + 0)', $selects[1], 'the exact run wraps the distance');
        } else {
            self::assertCount(1, $selects);
        }
    }

    // la forme du SQL garde l'index vectoriel : distance nue, triée par son alias, LIMIT
    public function testTheIndexServesTheQuery(): void
    {
        $platform = $this->connection->getPlatform()->getName();
        if ('SQLite' === $platform) {
            self::markTestSkipped('SQLite has no vector index.');
        }
        $this->passages->nearest('embedding', [1.0, 0.0, 0.0], limit: 3);
        $sql = $this->selects()[0];

        if ('PostgreSQL' === $platform) {
            // six lignes : sans cela le planificateur préférerait une lecture complète
            $this->connection->executeStatement('SET enable_seqscan = off');
        }
        $plan = implode("\n", array_map(static fn (array $row): string => implode(' ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $row)), $this->connection->executeQuery('EXPLAIN ' . $sql, ['nearest_vector' => [1.0, 0.0, 0.0]], ['nearest_vector' => 'vector'])->fetchAllAssociative()));
        if ('PostgreSQL' === $platform) {
            $this->connection->executeStatement('SET enable_seqscan = on');
        }

        self::assertMatchesRegularExpression('/vec_passage_[0-9a-f]{8}/', $plan, $plan);
    }

    public function testDistanceToInTheTypedQueryBuilder(): void
    {
        $p = Passage_::as('p');
        $query = new Vector([1.0, 0.0, 0.0]);

        $ordered = $this->em->query($p)->where($p->topic->eq('invoice'))->orderBy($p->embedding->distanceTo($query)->asc())->limit(2)->getResult();
        $projected = $this->em->query($p)->where($p->topic->eq('invoice'))->orderBy($p->embedding->distanceTo($query)->desc())
            ->selectInto(PassageDistance::class, title: $p->title, distance: $p->embedding->distanceTo($query, Distance::Euclidean))
            ->getResult();

        self::assertSame(['on the axis', 'close'], array_map(static fn (Passage $x): string => $x->title, $ordered));
        self::assertSame(['far', 'diagonal', 'close', 'on the axis'], array_map(static fn (PassageDistance $d): string => $d->title, $projected));
        self::assertEqualsWithDelta(0.0, $projected[3]->distance, 1e-6);
        self::assertEqualsWithDelta(sqrt(0.81 + 0.81), $projected[0]->distance, 1e-5);
    }

    // RG-09.1
    public function testWrongDimensionIsRefused(): void
    {
        $this->expectException(VectorDimensionMismatchException::class);
        $this->expectExceptionMessage(Passage::class . '::$embedding expects a vector of 3 dimensions, got 2');

        $this->passages->nearest('embedding', [1.0, 0.0]);
    }

    public function testFieldMustBeAVector(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('nearest() needs a field of type "vector".');

        // @phpstan-ignore prism.nearest.notVector (l'erreur à l'exécution, que l'extension signale avant)
        $this->passages->nearest('title', [1.0, 0.0, 0.0]);
    }
}
