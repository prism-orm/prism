<?php

declare(strict_types=1);

namespace PrismVsDoctrine\Bench;

use Doctrine\ORM\EntityManager as DoctrineEntityManager;
use PhpBench\Attributes as Bench;
use Prism\Orm\EntityManager;
use PrismVsDoctrine\Doctrine;
use PrismVsDoctrine\Prism;
use PrismVsDoctrine\Scenario;
use PrismVsDoctrine\Setup;

/**
 * N+1 : 100 commandes, puis le client et les lignes de chacune, par le même code dans les deux
 * ORM ; Doctrine aussi avec la jointure qu'on écrirait à la main. Le nombre de requêtes de chaque
 * variante est compté par bin/queries.php.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(10)]
#[Bench\Revs(5)]
#[Bench\Warmup(1)]
#[Bench\OutputTimeUnit('milliseconds', 1)]
final class NPlusOneBench
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
        Scenario::walk($this->prism->getRepository(Prism\PurchaseOrder::class)->findBy(['id' => Scenario::orderIds()]));
    }

    public function benchDoctrine(): void
    {
        $this->doctrine->clear();
        Scenario::walk($this->doctrine->getRepository(Doctrine\PurchaseOrder::class)->findBy(['id' => Scenario::orderIds()]));
    }

    public function benchDoctrineJoin(): void
    {
        $this->doctrine->clear();
        Scenario::walk(Scenario::doctrineJoin($this->doctrine));
    }
}
