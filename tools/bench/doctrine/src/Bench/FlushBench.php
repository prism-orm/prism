<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Bench;

use Doctrine\ORM\EntityManager as DoctrineEntityManager;
use PhpBench\Attributes as Bench;
use Prism\Orm\EntityManager;
use PrismVsDoctrine\Doctrine;
use PrismVsDoctrine\Prism;
use PrismVsDoctrine\Setup;

/**
 * Flush : 1 000 insertions en un flush() (dans inserted_item, vidée avant chaque série), puis la
 * modification d'un champ sur 1 000 entités gérées, lues avant la mesure.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(10)]
#[Bench\Revs(3)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 1)]
final class FlushBench
{
    private const int ROWS = 1_000;

    private EntityManager $prism;

    private DoctrineEntityManager $doctrine;

    /** @var list<Prism\Item> */
    private array $prismItems = [];

    /** @var list<Doctrine\Item> */
    private array $doctrineItems = [];

    private int $rev = 0;

    public function setUp(): void
    {
        $this->prism = Setup::prism();
        $this->doctrine = Setup::doctrine();
        $this->prism->getConnection()->executeStatement('DELETE FROM inserted_item');
        $ids = range(1, self::ROWS);
        $this->prismItems = $this->prism->getRepository(Prism\Item::class)->findBy(['id' => $ids]);
        $this->doctrineItems = array_values($this->doctrine->getRepository(Doctrine\Item::class)->findBy(['id' => $ids]));
    }

    public function benchPrismInsert(): void
    {
        $this->prism->clear();
        $created = new \DateTimeImmutable('2026-10-28 09:00:00');
        for ($i = 0; $i < self::ROWS; ++$i) {
            $item = new Prism\InsertedItem();
            [$item->code, $item->name, $item->price, $item->quantity, $item->createdAt] = ['NEW-' . $i, 'New item ' . $i, '12.50', $i, $created];
            $this->prism->persist($item);
        }
        $this->prism->flush();
    }

    public function benchDoctrineInsert(): void
    {
        $this->doctrine->clear();
        $created = new \DateTimeImmutable('2026-10-28 09:00:00');
        for ($i = 0; $i < self::ROWS; ++$i) {
            $item = new Doctrine\InsertedItem();
            [$item->code, $item->name, $item->price, $item->quantity, $item->createdAt] = ['NEW-' . $i, 'New item ' . $i, '12.50', $i, $created];
            $this->doctrine->persist($item);
        }
        $this->doctrine->flush();
    }

    public function benchPrismUpdate(): void
    {
        ++$this->rev;
        foreach ($this->prismItems as $item) {
            $item->quantity = $this->rev;
        }
        $this->prism->flush();
    }

    public function benchDoctrineUpdate(): void
    {
        ++$this->rev;
        foreach ($this->doctrineItems as $item) {
            $item->quantity = $this->rev;
        }
        $this->doctrine->flush();
    }
}
