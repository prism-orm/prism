<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Orm\EntityManager;
use Prism\Orm\Exception\UnpersistedRelationException;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Category;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Profile;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\PurchaseOrder;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Shipment;

/**
 * Relations ToOne sur base réelle : les contraintes de clé étrangère vérifient l'ordre des
 * INSERT, des UPDATE différés et des DELETE (RG-04.3, RG-04.4), sur les 4 bases.
 */
final class ToOneRelationsTest extends MappingSchemaTestCase
{
    private EntityManager $em;

    protected static function entities(): array
    {
        return [Customer::class, PurchaseOrder::class, Category::class, Profile::class, Shipment::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = new EntityManager($this->connection);
    }

    private function rowsIn(string $table): int
    {
        $count = $this->connection->createQueryBuilder()->select('COUNT(*)')->from($table)->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : -1;
    }

    // critère de sortie : cycle A ↔ B persisté et relu
    public function testCycleIsPersistedAndReadBack(): void
    {
        $customer = new Customer('Ada');
        $order = new PurchaseOrder($customer, 'PO-1');
        $customer->lastOrder = $order;
        $this->em->persist($order);
        $this->em->flush();
        $this->em->clear();

        $read = $this->em->find(Customer::class, $customer->id);
        self::assertNotNull($read);
        self::assertNotNull($read->lastOrder);
        self::assertSame($order->id, $read->lastOrder->id);
        self::assertSame('PO-1', $read->lastOrder->reference);
        self::assertSame($read, $read->lastOrder->customer, 'the cycle closes on the same instance');
    }

    // critère de sortie : catégorie auto-référencée persistée et relue
    public function testSelfReferencingCategoryIsPersistedAndReadBack(): void
    {
        $leaf = new Category('Leaf', new Category('Child', new Category('Root')));
        $this->em->persist($leaf);
        $this->em->flush();
        $this->em->clear();

        $read = $this->em->find(Category::class, $leaf->id);
        self::assertSame(['Leaf', 'Child', 'Root', null], [$read?->name, $read?->parent?->name, $read?->parent?->parent?->name, $read?->parent?->parent?->parent]);
    }

    // critère de sortie : entité final, relation readonly
    public function testFinalEntityWithAReadonlyRelation(): void
    {
        $order = new PurchaseOrder(new Customer('Ada'), 'PO-7');
        $this->em->persist($order);
        $this->em->persist(new Shipment($order, 'Colissimo'));
        $this->em->flush();
        $this->em->clear();

        $shipment = $this->em->getRepository(Shipment::class)->findOneBy(['order' => $order->id]);
        self::assertNotNull($shipment);
        self::assertSame(['PO-7', 'Ada'], [$shipment->order->reference, $shipment->order->customer->name]);
    }

    public function testDeletingACycleRespectsTheForeignKeys(): void
    {
        $customer = new Customer('Ada');
        $order = new PurchaseOrder($customer, 'PO-1');
        $customer->lastOrder = $order;
        $this->em->persist($order);
        $this->em->flush();

        $this->em->remove($customer);
        $this->em->remove($order);
        $this->em->flush();

        self::assertSame([0, 0], [$this->rowsIn('customer'), $this->rowsIn('purchase_order')]);
    }

    public function testReassignedRelationIsWritten(): void
    {
        $ada = new Customer('Ada');
        $bob = new Customer('Bob');
        $order = new PurchaseOrder($ada, 'PO-1');
        $this->em->persist($order);
        $this->em->persist($bob);
        $this->em->flush();

        $order->customer = $bob;
        $this->em->flush();
        $this->em->clear();

        self::assertSame('Bob', $this->em->find(PurchaseOrder::class, $order->id)?->customer->name);
        self::assertSame(1, $this->em->getRepository(PurchaseOrder::class)->count(['customer' => $bob]));
    }

    public function testInverseOneToOneIsReadBack(): void
    {
        $customer = new Customer('Ada');
        $this->em->persist($customer);
        $this->em->persist(new Profile($customer, 'Pioneer'));
        $this->em->flush();
        $this->em->clear();

        $read = $this->em->find(Customer::class, $customer->id);
        self::assertSame('Pioneer', $read?->profile?->bio);
        self::assertSame($read, $read->profile->customer);
    }

    // RG-04.2 : rien n'est écrit
    public function testUnpersistedRelationWritesNothing(): void
    {
        $customer = new Customer('Ada');
        $customer->referrer = new Customer('Unsaved');
        $this->em->persist($customer);

        try {
            $this->em->flush();
            self::fail('Expected UnpersistedRelationException.');
        } catch (\LogicException $e) {
            self::assertInstanceOf(UnpersistedRelationException::class, $e);
        }
        self::assertSame(0, $this->rowsIn('customer'));
    }
}
