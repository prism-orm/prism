<?php

declare(strict_types=1);

namespace Prism\Benchmarks;

use PhpBench\Attributes as Bench;
use Prism\Benchmarks\Entity\BenchItem;
use Prism\Benchmarks\Support\BenchDatabase;
use Prism\Dbal\Connection;
use Prism\Orm\EntityManager;
use Prism\Orm\Hydrator\ObjectHydrator;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;

/**
 * Critère de sortie de la phase 3 : hydratation de 10 000 entités < 50 ms (MySQL local).
 */
#[Bench\BeforeClassMethods('createDatabase')]
#[Bench\AfterClassMethods('dropDatabase')]
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\Revs(5)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 1)]
final class HydrationBench
{
    private const string DATABASE = 'prism_bench_hydration';

    private const int ROWS = 10_000;

    private Connection $connection;

    private EntityManager $em;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private ObjectHydrator $hydrator;

    /** @var ClassMetadata<BenchItem> */
    private ClassMetadata $metadata;

    public static function createDatabase(): void
    {
        BenchDatabase::create(self::DATABASE, self::ROWS);
    }

    public static function dropDatabase(): void
    {
        BenchDatabase::drop(self::DATABASE);
    }

    public function setUp(): void
    {
        $this->connection = BenchDatabase::connection(self::DATABASE);
        $this->em = new EntityManager($this->connection);
        $this->rows = $this->connection->createQueryBuilder()->from('bench_item')->executeQuery()->fetchAllAssociative();
        $this->hydrator = new ObjectHydrator($this->connection->getConversionContext(), $this->connection->getTypeRegistry());
        $this->metadata = new AttributeDriver()->loadMetadata(BenchItem::class);
    }

    /**
     * Chemin complet : SELECT, conversion, instanciation, identity map et snapshots.
     */
    #[Bench\Assert('mode(variant.time.avg) < 50 ms')]
    public function benchFindAll(): void
    {
        $this->em->clear();
        if (self::ROWS !== \count($this->em->getRepository(BenchItem::class)->findAll())) {
            throw new \LogicException('Unexpected row count.');
        }
    }

    /**
     * Hydratation seule, sans base ni UnitOfWork : le coût PHP de la conversion.
     */
    public function benchObjectHydratorOnly(): void
    {
        foreach ($this->rows as $row) {
            $this->hydrator->hydrate($this->metadata, $row);
        }
    }
}
