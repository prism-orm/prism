<?php

declare(strict_types=1);

namespace Prism\Benchmarks;

use PhpBench\Attributes as Bench;
use Prism\Benchmarks\Entity\BenchItem;
use Prism\Benchmarks\Support\BenchDatabase;
use Prism\Orm\EntityManager;

/**
 * Critère de sortie de la phase 3 : iterate() sur 1 million de lignes, mémoire plafonnée à
 * 64 Mo (RG-03.10). Le jeu de données est préparé dans un autre processus : le pic mémoire
 * mesuré est celui du parcours.
 */
#[Bench\BeforeClassMethods('createDatabase')]
#[Bench\AfterClassMethods('dropDatabase')]
#[Bench\Iterations(1)]
#[Bench\Revs(1)]
#[Bench\OutputTimeUnit('seconds', 1)]
final class IterateBench
{
    private const string DATABASE = 'prism_bench_iterate';

    // 1 000 lignes doublées 10 fois
    private const int SEED = 1_000;

    private const int DOUBLINGS = 10;

    public static function createDatabase(): void
    {
        BenchDatabase::create(self::DATABASE, self::SEED);

        $connection = BenchDatabase::connection(self::DATABASE);
        $platform = $connection->getPlatform();
        $columns = implode(', ', array_map($platform->quoteIdentifier(...), ['code', 'name', 'price', 'quantity', 'active', 'created_at']));
        // SQL standard, identique sur les plateformes, noms cités : code de préparation du benchmark
        $double = \sprintf('INSERT INTO %1$s (%2$s) SELECT %2$s FROM %1$s', $platform->quoteIdentifier('bench_item'), $columns);
        for ($i = 0; $i < self::DOUBLINGS; ++$i) {
            $connection->executeStatement($double);
        }
        $connection->close();
    }

    public static function dropDatabase(): void
    {
        BenchDatabase::drop(self::DATABASE);
    }

    #[Bench\Assert('mode(variant.mem.peak) < 64 mb')]
    public function benchIterateOneMillionRows(): void
    {
        // plafond réel : un dépassement est une erreur fatale, donc un benchmark en erreur
        ini_set('memory_limit', '64M');
        $em = new EntityManager(BenchDatabase::connection(self::DATABASE));
        $count = 0;
        foreach ($em->getRepository(BenchItem::class)->iterate(batchSize: 100) as $item) {
            ++$count;
        }

        if (self::SEED * 2 ** self::DOUBLINGS !== $count) {
            throw new \LogicException(\sprintf('Expected %d rows, iterated %d.', self::SEED * 2 ** self::DOUBLINGS, $count));
        }
    }
}
