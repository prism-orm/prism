<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Event\OnFlushEvent;
use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Exception\InvalidEntityStateException;
use Prism\Orm\Exception\UnpersistedRelationException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Category;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Customer;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\PurchaseOrder;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Shipment;
use Prism\Orm\UnitOfWork\EntityState;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Scénarios de référence des relations ToOne (phase 4, étape 2), écrits avant le code.
 *
 * Règles fixées ici, en complément de RG-04.2 à RG-04.4 :
 * - la FK écrite est l'identifiant de la cible, lu au moment de l'INSERT ;
 * - une FK relue devient une référence de l'identity map (un ghost si la cible n'y est pas) ;
 * - le snapshot contient la FK : réaffecter une relation met à jour sa seule colonne ;
 * - une cible NEW est persistée en cascade, ou lève UnpersistedRelationException avant toute
 *   écriture ; une cible DETACHED lève InvalidEntityStateException ;
 * - DELETE dans l'ordre inverse ; dans un cycle, la FK nullable est d'abord remise à NULL ;
 * - le côté inverse d'un OneToOne est relu par une requête sur la FK du côté propriétaire.
 */
final class UnitOfWorkRelationsTest extends TestCase
{
    private InMemoryStorage $storage;

    private TestDispatcher $events;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->events = new TestDispatcher();
        $this->uow = new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), $this->events);
    }

    /**
     * @return list<string> opérations « TYPE table » dans l'ordre
     */
    private function writes(): array
    {
        return array_values(array_map(static fn (array $o): string => $o[0] . ' ' . $o[1], array_filter($this->storage->log, static fn (array $o): bool => \in_array($o[0], ['INSERT', 'UPDATE', 'DELETE'], true))));
    }

    private function selectsOn(string $table): int
    {
        return \count(array_filter($this->storage->operations('SELECT'), static fn (array $o): bool => $o[0] === $table));
    }

    private function seedCustomer(int $id, ?int $lastOrder = null): void
    {
        $this->storage->seed('customer', 'id', ['id' => $id, 'name' => 'Customer ' . $id, 'last_order_id' => $lastOrder, 'referrer_id' => null]);
    }

    private function seedOrder(int $id, int $customer): void
    {
        $this->storage->seed('purchase_order', 'id', ['id' => $id, 'reference' => 'PO-' . $id, 'customer_id' => $customer]);
    }

    /**
     * @param list<PurchaseOrder> $orders
     *
     * @return list<int|null>
     */
    private static function ids(array $orders): array
    {
        return array_map(static fn (PurchaseOrder $o): ?int => $o->id, $orders);
    }

    // ---------------------------------------------------------------- écriture (RG-04.3)

    public function testForeignKeyIsTheIdentifierOfTheTarget(): void
    {
        $order = new PurchaseOrder(new Customer('Ada'), 'PO-1');
        $this->uow->persist($order);
        $this->uow->commit();

        self::assertSame(['INSERT customer', 'INSERT purchase_order'], $this->writes(), 'cascade: persist, target first');
        self::assertSame($order->customer->id, $this->storage->row('purchase_order', (int) $order->id)['customer_id']);
        self::assertSame(EntityState::Managed, $this->uow->getEntityState($order->customer));
    }

    public function testInsertOrderFollowsForeignKeysThenPersistOrder(): void
    {
        $ada = new Customer('Ada');
        $order = new PurchaseOrder($ada);
        $bob = new Customer('Bob');
        $this->uow->persist($order);
        $this->uow->persist($bob);
        $this->uow->persist($ada);
        $this->uow->commit();

        // la commande attend Ada ; Bob, indépendant, garde sa place devant Ada
        self::assertSame(['INSERT customer', 'INSERT customer', 'INSERT purchase_order'], $this->writes());
        self::assertSame([1, 2], [$bob->id, $ada->id]);
    }

    // RG-04.4
    public function testCycleIsInsertedWithNullThenUpdated(): void
    {
        $customer = new Customer('Ada');
        $order = new PurchaseOrder($customer, 'PO-1');
        $customer->lastOrder = $order;
        $this->uow->persist($order);
        $this->uow->commit();

        self::assertSame(['INSERT customer', 'INSERT purchase_order', 'UPDATE customer'], $this->writes());
        self::assertSame(['last_order_id'], $this->storage->operations('UPDATE')[0][1]);
        self::assertSame($order->id, $this->storage->row('customer', (int) $customer->id)['last_order_id']);

        $this->storage->log = [];
        $this->uow->commit();
        self::assertSame([], $this->writes(), 'the snapshot holds the deferred foreign key');
    }

    public function testSelfReferencingTreePersistedFromTheLeaf(): void
    {
        $leaf = new Category('Leaf', new Category('Child', new Category('Root')));
        $this->uow->persist($leaf);
        $this->uow->commit();

        self::assertSame(['INSERT category', 'INSERT category', 'INSERT category'], $this->writes());
        $child = $leaf->parent;
        self::assertNotNull($child);
        $root = $child->parent;
        self::assertNotNull($root);
        self::assertSame([1, 2, 3], [$root->id, $child->id, $leaf->id]);
        self::assertSame([null, 1, 2], [$this->storage->row('category', 1)['parent_id'], $this->storage->row('category', 2)['parent_id'], $this->storage->row('category', 3)['parent_id']]);
    }

    public function testRelationToAnEntityInsertedByTheSameFlush(): void
    {
        $this->seedCustomer(1);
        $customer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($customer);

        $customer->lastOrder = new PurchaseOrder($customer, 'PO-NEW');
        $this->uow->persist($customer->lastOrder);
        $this->uow->commit();

        self::assertSame(['INSERT purchase_order', 'UPDATE customer'], $this->writes());
        self::assertSame($customer->lastOrder->id, $this->storage->row('customer', 1)['last_order_id']);
    }

    // RG-03.6 : les identifiants générés reviennent à null
    public function testFailedCycleInsertRestoresIdentifiers(): void
    {
        $customer = new Customer('Ada');
        $order = new PurchaseOrder($customer);
        $customer->lastOrder = $order;
        $this->uow->persist($order);
        $this->storage->failOnInsert(2);

        try {
            $this->uow->commit();
            self::fail('Expected the simulated failure.');
        } catch (\RuntimeException) {
        }

        self::assertSame([null, null], [$customer->id, $order->id]);
        $this->storage->failOnInsert(null);
        $this->uow->commit();
        self::assertSame($order->id, $this->storage->row('customer', (int) $customer->id)['last_order_id']);
    }

    // ---------------------------------------------------------------- RG-04.2

    public function testNewTargetWithoutCascadeIsRefusedBeforeAnyWrite(): void
    {
        $customer = new Customer('Ada');
        $customer->referrer = new Customer('Unsaved');
        $this->uow->persist($customer);

        try {
            $this->uow->commit();
            self::fail('Expected UnpersistedRelationException.');
        } catch (UnpersistedRelationException $e) {
            self::assertSame('A new entity of class ' . Customer::class . ' was found through ' . Customer::class . '::$referrer, which does not cascade persist. Persist it explicitly, or add cascade: [Cascade::Persist] to the relation.', $e->getMessage());
            self::assertSame($customer->referrer, $e->entity);
        }
        self::assertSame(0, $this->storage->transactions);
    }

    public function testUnpersistedEntityFoundThroughACascadeNamesThePath(): void
    {
        $customer = new Customer('Ada');
        $customer->referrer = new Customer('Unsaved');
        $this->uow->persist(new PurchaseOrder($customer));

        $this->expectException(UnpersistedRelationException::class);
        $this->expectExceptionMessage('was found through ' . PurchaseOrder::class . '::$customer → ' . Customer::class . '::$referrer, which does not cascade persist.');

        $this->uow->commit();
    }

    public function testManagedEntityPointingToANewOneIsChecked(): void
    {
        $this->seedCustomer(1);
        $customer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($customer);
        $customer->referrer = new Customer('Unsaved');

        $this->expectException(UnpersistedRelationException::class);

        $this->uow->commit();
    }

    public function testDetachedTargetIsRefused(): void
    {
        $this->seedCustomer(1);
        $customer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($customer);
        $this->uow->detach($customer);
        $this->uow->persist(new PurchaseOrder($customer));

        $this->expectException(InvalidEntityStateException::class);
        $this->expectExceptionMessage('Cannot reference a DETACHED entity of class ' . Customer::class . ' from ' . PurchaseOrder::class . '::$customer: load it again with find() or getReference().');

        $this->uow->commit();
    }

    // ---------------------------------------------------------------- lecture (RG-04.7)

    public function testLoadedRelationIsAReferenceOfTheIdentityMap(): void
    {
        $this->seedCustomer(1);
        $this->seedOrder(10, 1);

        $order = $this->uow->find(PurchaseOrder::class, 10);
        self::assertNotNull($order);

        self::assertSame(1, $order->customer->id);
        self::assertSame(0, $this->selectsOn('customer'), 'the customer is a reference, not loaded');
        self::assertSame($order->customer, $this->uow->find(Customer::class, 1));
        self::assertSame('Customer 1', $order->customer->name);
        self::assertSame(1, $this->selectsOn('customer'));
    }

    public function testRelationToAnAlreadyManagedEntityIsThatEntity(): void
    {
        $this->seedCustomer(1);
        $this->seedOrder(10, 1);
        $customer = $this->uow->find(Customer::class, 1);

        self::assertSame($customer, $this->uow->find(PurchaseOrder::class, 10)?->customer);
    }

    public function testNullForeignKeyGivesNull(): void
    {
        $this->seedCustomer(1);

        self::assertNull($this->uow->find(Customer::class, 1)?->lastOrder);
    }

    public function testReferenceLoadedLaterHydratesItsRelations(): void
    {
        $this->seedCustomer(1);
        $this->seedOrder(10, 1);

        $reference = $this->uow->getReference(PurchaseOrder::class, 10);

        self::assertSame('PO-10', $reference->reference);
        self::assertSame(1, $reference->customer->id);
    }

    public function testRefreshReadsTheForeignKeyAgain(): void
    {
        $this->seedCustomer(1);
        $this->seedCustomer(2);
        $this->seedOrder(10, 1);
        $order = $this->uow->find(PurchaseOrder::class, 10);
        self::assertNotNull($order);

        $this->storage->setColumn('purchase_order', 10, 'customer_id', 2);
        $this->uow->refresh($order);

        self::assertSame(2, $order->customer->id);
    }

    public function testInverseOneToOneIsLoaded(): void
    {
        $this->seedCustomer(1);
        $this->seedCustomer(2);
        $this->storage->seed('profile', 'id', ['id' => 5, 'customer_id' => 1, 'bio' => 'Pioneer']);

        $customer = $this->uow->find(Customer::class, 1);

        self::assertNotNull($customer?->profile);
        self::assertSame('Pioneer', $customer->profile->bio);
        self::assertSame($customer, $customer->profile->customer, 'both sides are the same instances');
        self::assertNull($this->uow->find(Customer::class, 2)?->profile);
    }

    public function testFindByRelation(): void
    {
        $this->seedCustomer(1);
        $this->seedCustomer(2, lastOrder: 11);
        $this->seedOrder(10, 1);
        $this->seedOrder(11, 2);
        $this->seedOrder(12, 1);
        $customer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($customer);

        $ids = self::ids(...);
        self::assertSame([10, 12], $ids($this->uow->findBy(PurchaseOrder::class, ['customer' => $customer])));
        self::assertSame([11], $ids($this->uow->findBy(PurchaseOrder::class, ['customer' => 2])));
        self::assertSame([10, 11, 12], $ids($this->uow->findBy(PurchaseOrder::class, ['customer' => [$customer, 2]])));
        self::assertSame([1], array_map(static fn (Customer $c): ?int => $c->id, $this->uow->findBy(Customer::class, ['lastOrder' => null])));
        self::assertSame(2, $this->uow->count(PurchaseOrder::class, ['customer' => $customer]));
    }

    // ---------------------------------------------------------------- mise à jour (RG-03.4, RG-03.5)

    public function testUnchangedRelationGivesNoUpdateAndLoadsNothing(): void
    {
        $this->seedCustomer(1);
        $this->seedOrder(10, 1);
        $this->uow->find(PurchaseOrder::class, 10);

        $this->uow->commit();

        self::assertSame([], $this->writes());
        self::assertSame(0, $this->selectsOn('customer'));
    }

    public function testReassigningARelationUpdatesOnlyItsJoinColumn(): void
    {
        $this->seedCustomer(1);
        $this->seedCustomer(2);
        $this->seedOrder(10, 1);
        $order = $this->uow->find(PurchaseOrder::class, 10);
        self::assertNotNull($order);
        $previous = $order->customer;

        $order->customer = $this->uow->getReference(Customer::class, 2);
        $this->uow->commit();

        self::assertSame([['purchase_order', ['customer_id']]], $this->storage->operations('UPDATE'));
        self::assertSame(2, $this->storage->row('purchase_order', 10)['customer_id']);
        $event = $this->events->ofType(PreUpdateEvent::class)[0] ?? null;
        self::assertInstanceOf(PreUpdateEvent::class, $event);
        self::assertSame(['customer' => [$previous, $order->customer]], $event->changeSet, 'the change set holds the entities');
    }

    // ---------------------------------------------------------------- suppression (RG-04.3, RG-04.4)

    public function testDeletionsAreReversed(): void
    {
        $this->seedCustomer(1);
        $this->seedOrder(10, 1);
        $customer = $this->uow->find(Customer::class, 1);
        $order = $this->uow->find(PurchaseOrder::class, 10);
        self::assertNotNull($customer);
        self::assertNotNull($order);

        $this->uow->remove($customer);
        $this->uow->remove($order);
        $this->uow->commit();

        self::assertSame(['DELETE purchase_order', 'DELETE customer'], $this->writes());
    }

    public function testDeletingACycleClearsTheNullableForeignKeyFirst(): void
    {
        $this->seedCustomer(1, lastOrder: 10);
        $this->seedOrder(10, 1);
        $customer = $this->uow->find(Customer::class, 1);
        $order = $this->uow->find(PurchaseOrder::class, 10);
        self::assertNotNull($customer);
        self::assertNotNull($order);

        $this->uow->remove($order);
        $this->uow->remove($customer);
        $this->uow->commit();

        self::assertSame(['UPDATE customer', 'DELETE purchase_order', 'DELETE customer'], $this->writes());
        self::assertSame([], $this->storage->tables['customer']);
        self::assertSame([['customer', ['last_order_id']]], $this->storage->operations('UPDATE'));
    }

    public function testRemovedEntityReferencingANewOneDoesNotBlockTheFlush(): void
    {
        $this->seedCustomer(1);
        $customer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($customer);
        $customer->referrer = new Customer('Never saved');

        $this->uow->remove($customer);
        $this->uow->commit();

        self::assertSame(['DELETE customer'], $this->writes());
    }

    // ---------------------------------------------------------------- entité final, relation readonly

    public function testReadonlyRelationOfAFinalEntity(): void
    {
        $order = new PurchaseOrder(new Customer('Ada'), 'PO-1');
        $this->uow->persist($order);
        $this->uow->persist(new Shipment($order, 'Colissimo'));
        $this->uow->commit();
        $this->uow->clear();

        $shipment = $this->uow->find(Shipment::class, 1);
        self::assertNotNull($shipment);
        self::assertSame($order->id, $shipment->order->id);
        self::assertSame('PO-1', $shipment->order->reference);

        $this->uow->refresh($shipment);
        $this->uow->commit();
        self::assertSame($order->id, $shipment->order->id, 'a readonly relation is never written again');
    }

    // ---------------------------------------------------------------- compléments (mutants)

    public function testRelationSetInOnFlushIsPersistedInCascade(): void
    {
        $this->storage->seed('category', 'id', ['id' => 1, 'name' => 'Orphan', 'parent_id' => null]);
        $category = $this->uow->find(Category::class, 1);
        self::assertNotNull($category);
        $this->events->listen(OnFlushEvent::class, static function () use ($category): void {
            $category->parent = new Category('Late parent');
        });

        $this->uow->commit();

        self::assertSame(['INSERT category', 'UPDATE category'], $this->writes());
        self::assertSame($category->parent?->id, $this->storage->row('category', 1)['parent_id']);
    }

    public function testOnFlushSeesEntitiesPersistedInCascade(): void
    {
        $order = new PurchaseOrder(new Customer('Ada'));
        $this->uow->persist($order);
        $scheduled = [];
        $this->events->listen(OnFlushEvent::class, static function (OnFlushEvent $event) use (&$scheduled): void {
            $scheduled = $event->unitOfWork->getScheduledInsertions();
        });

        $this->uow->commit();

        self::assertSame([$order, $order->customer], $scheduled);
    }

    public function testChangeSetWithARelationAndAField(): void
    {
        $this->seedCustomer(1);
        $this->seedCustomer(2);
        $this->seedOrder(10, 1);
        $order = $this->uow->find(PurchaseOrder::class, 10);
        self::assertNotNull($order);

        $order->customer = $this->uow->getReference(Customer::class, 2);
        $order->reference = 'PO-10b';
        $this->uow->commit();

        $event = $this->events->ofType(PreUpdateEvent::class)[0] ?? null;
        self::assertInstanceOf(PreUpdateEvent::class, $event);
        self::assertSame(['reference', 'customer'], array_keys($event->changeSet));
    }

    public function testChangeSetWithTwoRelations(): void
    {
        $this->seedCustomer(1);
        $this->seedCustomer(2);
        $this->seedOrder(10, 2);
        $customer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($customer);

        $customer->lastOrder = $this->uow->getReference(PurchaseOrder::class, 10);
        $customer->referrer = $this->uow->getReference(Customer::class, 2);
        $this->uow->commit();

        $event = $this->events->ofType(PreUpdateEvent::class)[0] ?? null;
        self::assertInstanceOf(PreUpdateEvent::class, $event);
        self::assertSame(['lastOrder', 'referrer'], array_keys($event->changeSet));
    }

    public function testSecondForeignKeyOfAnEntityIsTracked(): void
    {
        $this->seedCustomer(1);
        $this->seedCustomer(2);
        $customer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($customer);

        $customer->referrer = $this->uow->getReference(Customer::class, 2);
        $this->uow->commit();

        self::assertSame([['customer', ['referrer_id']]], $this->storage->operations('UPDATE'));
    }

    public function testCriteriaOnTheSecondRelationAndOnSeveralFields(): void
    {
        $this->seedCustomer(1);
        $this->storage->seed('customer', 'id', ['id' => 2, 'name' => 'Referred', 'last_order_id' => null, 'referrer_id' => 1]);
        $this->seedOrder(10, 1);
        $this->seedOrder(11, 1);
        $referrer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($referrer);

        self::assertSame(['Referred'], array_map(static fn (Customer $c): string => $c->name, $this->uow->findBy(Customer::class, ['referrer' => $referrer])));
        self::assertSame([11], self::ids($this->uow->findBy(PurchaseOrder::class, ['customer' => $referrer, 'reference' => 'PO-11'])));
    }

    public function testCriteriaWithAnEntityWithoutIdentifierAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The criteria on ' . PurchaseOrder::class . '::$customer use an entity that has no identifier yet; flush it first.');

        $this->uow->findBy(PurchaseOrder::class, ['customer' => new Customer('Unsaved')]);
    }

    // RG-02.6 : une relation inverse readonly n'est pas relue
    public function testRefreshNeverRewritesAReadonlyInverseRelation(): void
    {
        $this->seedCustomer(1);
        $this->storage->seed('profile', 'id', ['id' => 5, 'customer_id' => 1, 'bio' => 'Pioneer']);
        $customer = $this->uow->find(Customer::class, 1);
        self::assertNotNull($customer);
        $profile = $customer->profile;
        $this->storage->deleteRow('profile', 5);

        $this->uow->refresh($customer);

        self::assertSame($profile, $customer->profile);
    }

    public function testRefreshReadsARelationDeclaredAfterAReadonlyOne(): void
    {
        $this->seedCustomer(1);
        $this->seedCustomer(2);
        $this->seedOrder(10, 1);
        $this->storage->seed('shipment', 'id', ['id' => 3, 'order_id' => 10, 'carrier' => 'Colissimo', 'recipient_id' => 1]);
        $shipment = $this->uow->find(Shipment::class, 3);
        self::assertNotNull($shipment);

        $this->storage->setColumn('shipment', 3, 'recipient_id', 2);
        $this->uow->refresh($shipment);

        self::assertSame(2, $shipment->recipient?->id);
    }
}
