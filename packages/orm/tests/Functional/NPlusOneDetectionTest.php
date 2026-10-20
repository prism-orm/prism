<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\Debug\N1Detector;
use Prism\Orm\Debug\NPlusOneDetected;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Depot;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Seller;

/**
 * Critère de sortie : détecteur N+1 testé avec origine correcte (RG-04.15), sur les 4 bases.
 */
final class NPlusOneDetectionTest extends MappingSchemaTestCase
{
    private N1Detector $detector;

    private TestDispatcher $events;

    private EntityManager $em;

    protected static function entities(): array
    {
        return [Order::class, Customer::class, Country::class, Account::class, Group::class, Seller::class, Depot::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new TestDispatcher();
        $this->detector = new N1Detector($this->events);
        // la Connection observée par le détecteur ; SQLite en mémoire : le schéma est réappliqué
        $this->connection->close();
        $this->connection = TestDatabase::connection(new Configuration()->withObservers([$this->detector]));
        if (':memory:' === $this->connection->getDatabase()) {
            $this->migrateTo($this->target());
        }
        $this->em = new EntityManager($this->connection);

        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            $customer = new Customer('Ada');
            $em->persist($customer);
            for ($i = 1; $i <= 8; ++$i) {
                $em->persist($seller = new Seller('S' . $i));
                $em->persist(new Order($customer, 'R' . $i, $seller));
            }
        });
        $this->em->clear();
        $this->detector->reset();
        $this->events->dispatched = [];
    }

    public function testLoopOverARelationWithoutBatchIsDetectedAtItsLine(): void
    {
        $orders = $this->em->getRepository(Order::class)->findAll();

        $names = [];
        $line = __LINE__ + 2;
        foreach ($orders as $order) {
            $names[] = $order->seller?->name;
        }

        self::assertCount(8, $names);
        $sellers = array_values(array_filter($this->events->ofType(NPlusOneDetected::class), static fn (object $e): bool => $e instanceof NPlusOneDetected && str_contains($e->sql, 'seller') && !str_contains($e->sql, 'seller_id')));
        self::assertCount(1, $sellers, 'published once');
        self::assertSame(__FILE__ . ':' . $line, $sellers[0]->origin);
        self::assertSame(6, $sellers[0]->count);
        // chaque vendeur charge aussi ses commandes (Fetch::Eager, une requête par vendeur hors lot)
        self::assertCount(2, $this->detector->getDetections());
    }

    // un flush de plusieurs entités répète ses INSERT : ce n'est pas un N+1
    public function testWritesOfAFlushAreNotDetected(): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            for ($i = 1; $i <= 20; ++$i) {
                $em->persist(new Seller('More ' . $i));
            }
        });

        self::assertSame([], $this->detector->getDetections());
    }

    public function testBatchedLoopIsNotDetected(): void
    {
        $orders = $this->em->getRepository(Order::class)->findAll();

        foreach ($orders as $order) {
            self::assertSame('Ada', $order->customer->name);
        }

        self::assertSame([], $this->detector->getDetections());
    }
}
