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
 * Hydratation : findAll() de 10 000 entités sans relation, identity map vidée avant chaque appel.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(10)]
#[Bench\Revs(5)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 1)]
final class HydrationBench
{
    private EntityManager $prism;

    private DoctrineEntityManager $doctrine;

    public function setUp(): void
    {
        $this->prism = Setup::prism();
        $this->doctrine = Setup::doctrine();
    }

    public function benchPrism(): void
    {
        $this->prism->clear();
        if (Setup::ITEMS !== \count($this->prism->getRepository(Prism\Item::class)->findAll())) {
            throw new \LogicException('Unexpected row count.');
        }
    }

    public function benchDoctrine(): void
    {
        $this->doctrine->clear();
        if (Setup::ITEMS !== \count($this->doctrine->getRepository(Doctrine\Item::class)->findAll())) {
            throw new \LogicException('Unexpected row count.');
        }
    }
}
