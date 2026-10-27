<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\VectorDimensionMismatchException;
use Prism\Orm\Tests\Functional\VectorFixtures\Doc;
use Prism\Orm\Tests\Functional\VectorFixtures\Doc_;
use Prism\Orm\Vector\Vector;

/**
 * Vecteurs dans l'ORM sur MariaDB, PostgreSQL et SQLite (ADR 0017) : écriture par le SQL de
 * paramètre de la plateforme, dimension vérifiée avant l'envoi (RG-09.1), Vector paresseux chargé
 * par lot ou par withVectors() (RG-09.6), changement détecté sans charger le vecteur.
 */
final class LazyVectorTest extends MappingSchemaTestCase
{
    private TestLogger $logger;

    private EntityManager $em;

    protected static function entities(): array
    {
        return [Doc::class];
    }

    protected function setUp(): void
    {
        if (null !== TestDatabase::url() && !TestDatabase::connection()->getPlatform()->supportsVectors()) {
            self::markTestSkipped('MySQL has no vector type (ADR 0017).');
        }
        parent::setUp();
        $this->logger = new TestLogger();
        $this->connection->close();
        $this->connection = TestDatabase::connection(new Configuration($this->logger));
        if (':memory:' === $this->connection->getDatabase()) {
            $this->migrateTo($this->target());
        }
        $this->em = new EntityManager($this->connection);
    }

    /**
     * @return list<string>
     *
     * @phpstan-impure
     */
    private function statements(string $prefix): array
    {
        return array_values(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, $prefix)));
    }

    private function startCounting(): void
    {
        $this->em->clear();
        $this->logger->records = [];
    }

    /**
     * @return list<Doc>
     */
    private function seed(int $count = 3): array
    {
        $docs = [];
        for ($i = 1; $i <= $count; ++$i) {
            $this->em->persist($docs[] = new Doc('doc ' . $i, new Vector([(float) $i, 0.5, -1.0]), 1 === $i ? new Vector([0.0, 1.0, 0.0]) : null, [0.25, 0.5, (float) $i]));
        }
        $this->em->flush();

        return $docs;
    }

    private static function isGhost(object $value): bool
    {
        return new \ReflectionClass($value)->isUninitializedLazyObject($value);
    }

    public function testWriteAndReadBack(): void
    {
        [$doc] = $this->seed(1);
        $this->em->clear();

        $read = $this->em->find(Doc::class, $doc->id);

        self::assertNotNull($read);
        self::assertSame([1.0, 0.5, -1.0], $read->embedding->toArray());
        self::assertNotNull($read->draft);
        self::assertSame([0.0, 1.0, 0.0], $read->draft->toArray());
        self::assertSame([0.25, 0.5, 1.0], $read->raw);
    }

    // RG-09.6 : la colonne n'est pas lue avec l'entité, seulement sa nullité
    public function testLazyVectorIsAGhostUntilRead(): void
    {
        [, $second] = $this->seed(2);
        $this->startCounting();

        $doc = $this->em->find(Doc::class, $second->id);

        self::assertNotNull($doc);
        self::assertTrue(self::isGhost($doc->embedding));
        self::assertNull($doc->draft, 'a NULL lazy vector is null, without a query');
        self::assertSame([0.25, 0.5, 2.0], $doc->raw, 'a list<float> is always loaded');
        self::assertCount(1, $this->statements('SELECT'));
        self::assertSame(3, $doc->embedding->dimensions());
        self::assertCount(2, $this->statements('SELECT'));
    }

    // RG-09.6, pilier 1 : les vecteurs d'une liste en une requête, pas une par entité
    public function testVectorsOfAListLoadInOneQuery(): void
    {
        $this->seed(5);
        $this->startCounting();

        $docs = $this->em->getRepository(Doc::class)->findBy([], ['id' => \Prism\Dbal\Query\Order::Asc]);
        $values = array_map(static fn (Doc $d): float => $d->embedding->toArray()[0], $docs);

        self::assertSame([1.0, 2.0, 3.0, 4.0, 5.0], $values);
        self::assertCount(2, $this->statements('SELECT'), implode("\n", $this->statements('SELECT')));
    }

    public function testWithVectorsReadsThemWithTheEntities(): void
    {
        $this->seed(3);
        $this->startCounting();
        $d = Doc_::as('d');

        $docs = $this->em->query($d)->withVectors()->orderBy($d->id->asc())->getResult();

        self::assertFalse(self::isGhost($docs[0]->embedding));
        self::assertSame([3.0, 0.5, -1.0], $docs[2]->embedding->toArray());
        self::assertSame([0.0, 1.0, 0.0], $docs[0]->draft?->toArray());
        self::assertCount(1, $this->statements('SELECT'));
    }

    // RG-03.4 : un vecteur chargé mais inchangé ne produit pas d'UPDATE ; un vecteur remplacé, si
    public function testOnlyAReplacedVectorIsUpdated(): void
    {
        [$doc] = $this->seed(1);
        $this->startCounting();

        $read = $this->em->find(Doc::class, $doc->id);
        self::assertNotNull($read);
        self::assertCount(3, $read->embedding->toArray());
        $this->em->flush();
        self::assertSame([], $this->statements('UPDATE'));

        $read->embedding = new Vector([9.0, 8.0, 7.0]);
        $this->em->flush();
        self::assertCount(1, $this->statements('UPDATE'));

        $this->em->clear();
        self::assertSame([9.0, 8.0, 7.0], $this->em->find(Doc::class, $doc->id)?->embedding->toArray());
    }

    // un vecteur remplacé sans avoir été lu : écrit, et l'ancien n'est jamais chargé
    public function testReplacingAnUnreadVector(): void
    {
        [$doc] = $this->seed(1);
        $this->startCounting();

        $read = $this->em->find(Doc::class, $doc->id);
        self::assertNotNull($read);
        $read->embedding = new Vector([4.0, 5.0, 6.0]);
        $read->draft = null;
        $this->em->flush();

        self::assertCount(1, $this->statements('SELECT'));
        self::assertCount(1, $this->statements('UPDATE'));
        $this->em->clear();
        $again = $this->em->find(Doc::class, $doc->id);
        self::assertSame([4.0, 5.0, 6.0], $again?->embedding->toArray());
        self::assertNull($again->draft);
    }

    // RG-09.1 : refusé avant l'envoi, rien n'est écrit (RG-03.6)
    public function testWrongDimensionIsRefusedBeforeTheDatabase(): void
    {
        $this->em->persist(new Doc('short', new Vector([1.0, 2.0])));

        try {
            $this->em->flush();
            self::fail('Expected VectorDimensionMismatchException.');
        } catch (VectorDimensionMismatchException $e) {
            self::assertSame(Doc::class . '::$embedding expects a vector of 3 dimensions, got 2: check the embedding model of this field.', $e->getMessage());
        }
        self::assertSame([], $this->statements('INSERT'));
    }

    // un refresh relit la ligne : le vecteur redevient paresseux, à sa valeur en base
    public function testRefreshMakesTheVectorLazyAgain(): void
    {
        [$doc] = $this->seed(1);
        $this->connection->createQueryBuilder()->update('doc')->set('embedding', [7.0, 7.0, 7.0], 'vector')->executeStatement();

        $this->em->refresh($doc);

        self::assertTrue(self::isGhost($doc->embedding));
        self::assertSame([7.0, 7.0, 7.0], $doc->embedding->toArray());
    }
}
