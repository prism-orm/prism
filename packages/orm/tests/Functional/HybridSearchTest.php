<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Repository\EntityRepository;
use Prism\Orm\Tests\Functional\VectorFixtures\Article;
use Prism\Orm\Tests\Functional\VectorFixtures\Doc;
use Prism\Orm\Tests\Functional\VectorFixtures\Passage;
use Prism\Orm\Vector\HybridOptions;
use Prism\Orm\Vector\HybridResult;
use Prism\Orm\Vector\Vector;

/**
 * hybridSearch() sur MariaDB, PostgreSQL et SQLite (RG-09.5, ADR 0017) : candidats plein texte
 * (un OU sur les mots) et vectoriels, fusionnés par Reciprocal Rank Fusion, rangs exposés,
 * critères appliqués aux deux listes.
 */
final class HybridSearchTest extends MappingSchemaTestCase
{
    private TestLogger $logger;

    private EntityManager $em;

    /** @var EntityRepository<Article> */
    private EntityRepository $articles;

    protected static function entities(): array
    {
        return [Article::class, Passage::class, Doc::class];
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
        $this->articles = $this->em->getRepository(Article::class);

        // requête « partial payment », vecteur [1, 0, 0] : seul le premier est bon des deux côtés
        foreach ([
            ['invoice', 'Partial payment of an invoice', 'Record the partial payment of an invoice.', [1.0, 0.0, 0.0]],
            ['invoice', 'Invoice numbering', 'Number invoices per company.', [0.9, 0.1, 0.0]],
            ['stock', 'Partial deliveries', 'Ship part of an order from stock.', [0.0, 1.0, 0.0]],
            ['invoice', 'Record a payment', 'Register a customer payment.', [0.7, 0.3, 0.0]],
            ['stock', 'Stock levels', 'Count the products in stock.', [0.1, 0.9, 0.0]],
        ] as [$topic, $title, $body, $vector]) {
            $this->em->persist(new Article($topic, $title, $body, new Vector($vector)));
        }
        $this->em->flush();
        $this->em->clear();
        $this->logger->records = [];
    }

    /**
     * @param list<HybridResult<Article>> $results
     *
     * @return list<string>
     */
    private static function titles(array $results): array
    {
        return array_map(static fn (HybridResult $r): string => $r->entity->title, $results);
    }

    public function testFusesTextAndVectorRankings(): void
    {
        $results = $this->articles->hybridSearch('partial payment', new Vector([1.0, 0.0, 0.0]), limit: 5);
        $titles = self::titles($results);

        // le seul bon des deux côtés, puis ceux qui ont un mot cherché, puis les vecteurs seuls
        self::assertSame('Partial payment of an invoice', $titles[0]);
        self::assertSame([1, 1], [$results[0]->textRank, $results[0]->vectorRank]);
        self::assertEqualsCanonicalizing(['Record a payment', 'Partial deliveries'], \array_slice($titles, 1, 2));
        self::assertSame(['Invoice numbering', 'Stock levels'], \array_slice($titles, 3));
        self::assertSame([null, 2], [$results[3]->textRank, $results[3]->vectorRank]);
        self::assertEqualsWithDelta(2 / 61, $results[0]->score, 1e-12, 'RRF, k = 60');
        self::assertEqualsWithDelta(1 / 62, $results[3]->score, 1e-12);
        self::assertGreaterThan($results[3]->score, $results[2]->score);
    }

    // le plein texte trouve un document de chaque mot (OU), quelle que soit la base
    public function testEveryDocumentWithOneOfTheWordsIsACandidate(): void
    {
        $results = $this->articles->hybridSearch('partial payment', [1.0, 0.0, 0.0], limit: 5, options: new HybridOptions(vectorWeight: 0.0));

        self::assertCount(3, array_filter($results, static fn (HybridResult $r): bool => null !== $r->textRank));
        self::assertSame('Partial payment of an invoice', $results[0]->entity->title);
    }

    // poids du texte à 0 : l'ordre vectoriel seul, les rangs plein texte restent exposés
    public function testWeights(): void
    {
        $results = $this->articles->hybridSearch('partial payment', [1.0, 0.0, 0.0], limit: 5, options: new HybridOptions(textWeight: 0.0));

        self::assertSame(['Partial payment of an invoice', 'Invoice numbering', 'Record a payment', 'Stock levels', 'Partial deliveries'], self::titles($results));
        self::assertNotNull($results[2]->textRank);
    }

    // RG-09.4 : les critères filtrent les deux listes avant la fusion
    public function testCriteriaFilterBothLists(): void
    {
        $results = $this->articles->hybridSearch('partial payment', [1.0, 0.0, 0.0], limit: 5, criteria: ['topic' => 'stock']);

        self::assertSame(['Partial deliveries', 'Stock levels'], self::titles($results));
        self::assertSame([1, 2], [$results[0]->textRank, $results[0]->vectorRank]);
    }

    public function testNoWordFoundLeavesTheVectorRanking(): void
    {
        $results = $this->articles->hybridSearch('xylophone', [0.0, 1.0, 0.0], limit: 2);

        self::assertSame(['Partial deliveries', 'Stock levels'], self::titles($results));
        self::assertNull($results[0]->textRank);
    }

    // trois requêtes : texte, vecteur, entités ; MariaDB relance le vecteur en exact (5 lignes < 40 candidats)
    public function testQueries(): void
    {
        $this->articles->hybridSearch('partial payment', [1.0, 0.0, 0.0], limit: 3);

        $selects = array_values(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
        self::assertCount('MariaDB' === $this->connection->getPlatform()->getName() ? 4 : 3, $selects, implode("\n", $selects));
    }

    // l'index plein texte sert la condition : FULLTEXT sous MariaDB, GIN sur l'expression sous PostgreSQL
    public function testTheFullTextIndexServesTheQuery(): void
    {
        $platform = $this->connection->getPlatform()->getName();
        if ('SQLite' === $platform) {
            self::markTestSkipped('SQLite has no full-text index.');
        }
        $this->articles->hybridSearch('partial payment', [1.0, 0.0, 0.0], limit: 3);
        $sql = array_values(array_filter($this->logger->sqlStatements(), static fn (string $s): bool => str_contains($s, 'prism_score')))[0];

        if ('PostgreSQL' === $platform) {
            // cinq lignes : sans cela le planificateur préférerait une lecture complète
            $this->connection->executeStatement('SET enable_seqscan = off');
        }
        $params = ['fulltext_score' => 'partial payment', 'fulltext_match' => 'partial payment'];
        $plan = implode("\n", array_map(static fn (array $row): string => implode(' ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $row)), $this->connection->executeQuery('EXPLAIN ' . $sql, $params)->fetchAllAssociative()));
        if ('PostgreSQL' === $platform) {
            $this->connection->executeStatement('SET enable_seqscan = on');
        }

        self::assertMatchesRegularExpression('/ftx_article_[0-9a-f]{8}/', $plan, $plan);
    }

    public function testEntityWithoutFullTextIndex(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('hybridSearch() needs a #[FullTextIndex].');

        $this->em->getRepository(Passage::class)->hybridSearch('x', [1.0, 0.0, 0.0]);
    }

    public function testSeveralVectorFieldsNeedTheField(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('hybridSearch() needs HybridOptions::$field: the entity has several vector fields (embedding, draft, raw).');

        $this->em->getRepository(Doc::class)->hybridSearch('x', [1.0, 0.0, 0.0]);
    }
}
