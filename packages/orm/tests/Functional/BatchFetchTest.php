<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Tests\Functional\BatchFixtures\Bill;
use Prism\Orm\Tests\Functional\BatchFixtures\Client;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Account;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Country;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Depot;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Group;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Order;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Seller;

/**
 * Batch fetching sur base réelle (pilier 1) : requêtes comptées par le logger PSR-3 de la
 * Connection, sur les 4 bases ; tables « order » et « group » : mots réservés (RG-01.3).
 */
final class BatchFetchTest extends MappingSchemaTestCase
{
    private TestLogger $logger;

    private EntityManager $em;

    protected static function entities(): array
    {
        return [Client::class, Bill::class, Order::class, Customer::class, Country::class, Account::class, Group::class, Seller::class, Depot::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // une connexion journalisée ; SQLite en mémoire : une base neuve, le schéma est réappliqué
        $this->logger = new TestLogger();
        $this->connection->close();
        $this->connection = TestDatabase::connection(new Configuration($this->logger));
        if (':memory:' === $this->connection->getDatabase()) {
            $this->migrateTo($this->target());
        }
        $this->em = new EntityManager($this->connection);
    }

    private function selects(): int
    {
        return \count(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
    }

    private function startCounting(): void
    {
        $this->em->clear();
        $this->logger->records = [];
    }

    // critère de sortie de la phase 4 : 100 commandes et leur client → 2 requêtes au lieu de 101
    public function testHundredBillsAndTheirClientsInTwoQueries(): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            for ($i = 1; $i <= 100; ++$i) {
                $em->persist(new Bill($client = new Client('Client ' . $i), 'B' . $i));
                $em->persist($client);
            }
        });
        $this->startCounting();

        $names = [];
        foreach ($this->em->getRepository(Bill::class)->findAll() as $bill) {
            $names[$bill->client->name] = true;
        }

        self::assertCount(100, $names);
        self::assertSame(2, $this->selects());
    }

    public function testCollectionsInverseSideAndJoinTableByBatch(): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            $groups = [new Group('gold'), new Group('silver')];
            foreach ($groups as $group) {
                $em->persist($group);
            }
            for ($i = 1; $i <= 20; ++$i) {
                $customer = new Customer('Customer ' . $i);
                $customer->groups->add($groups[$i % 2]);
                $em->persist($customer);
                foreach ([1, 2, 3] as $n) {
                    $em->persist(new Order($customer, \sprintf('R%02d-%d', $i, $n)));
                }
                if (0 === $i % 2) {
                    $em->persist(new Account($customer, $i));
                }
            }
        });
        $this->startCounting();

        $customers = $this->em->getRepository(Customer::class)->findAll();
        self::assertSame(2, $this->selects(), 'customers, then their accounts in one query');

        foreach ($customers as $customer) {
            self::assertCount(3, $customer->orders);
            self::assertCount(1, $customer->groups);
        }
        self::assertSame(4, $this->selects(), 'the orders and the groups of the whole cohort, one query each');

        $first = $customers[0];
        self::assertSame(['R01-3', 'R01-2', 'R01-1'], array_map(static fn (Order $o): string => $o->reference, array_values($first->orders->toArray())), 'orderBy kept');
        self::assertSame('silver', $first->groups->first()?->name);
        self::assertNull($first->account);
        self::assertSame(2, $customers[1]->account?->balance);
        self::assertSame(4, $this->selects());
    }

    public function testEagerRelationIsLoadedWithTheCohort(): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            $depots = [new Depot('north'), new Depot('south')];
            $customer = new Customer('Ada');
            $em->persist($customer);
            for ($i = 1; $i <= 6; ++$i) {
                $em->persist($depots[$i % 2]);
                $em->persist(new Order($customer, 'R' . $i, depot: $depots[$i % 2]));
            }
        });
        $this->startCounting();

        $orders = $this->em->getRepository(Order::class)->findAll();

        self::assertSame(2, $this->selects());
        self::assertSame('south', $orders[0]->depot?->name);
        self::assertSame(2, $this->selects());
    }
}
