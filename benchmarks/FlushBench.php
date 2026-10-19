<?php

declare(strict_types=1);

namespace Prism\Benchmarks;

use PhpBench\Attributes as Bench;
use Prism\Benchmarks\Entity\BenchItem;
use Prism\Benchmarks\Support\BenchDatabase;
use Prism\Orm\EntityManager;

/**
 * Critère de sortie de la phase 3 : flush de 1 000 UPDATE < 300 ms (MySQL local).
 *
 * Chaque révolution modifie les 1 000 entités (boucle négligeable) puis les écrit : détection
 * des changements, une transaction, 1 000 UPDATE d'une colonne.
 */
#[Bench\BeforeClassMethods('createDatabase')]
#[Bench\AfterClassMethods('dropDatabase')]
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
#[Bench\Revs(5)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 1)]
final class FlushBench
{
    private const string DATABASE = 'prism_bench_flush';

    private const int ROWS = 1_000;

    private EntityManager $em;

    /** @var list<BenchItem> */
    private array $items = [];

    private int $revision = 0;

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
        $this->em = new EntityManager(BenchDatabase::connection(self::DATABASE));
        $this->items = $this->em->getRepository(BenchItem::class)->findAll();
    }

    #[Bench\Assert('mode(variant.time.avg) < 300 ms')]
    public function benchFlushThousandUpdates(): void
    {
        ++$this->revision;
        foreach ($this->items as $item) {
            $item->quantity = $this->revision;
        }
        $this->em->flush();
    }
}
