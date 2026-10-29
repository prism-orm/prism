<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Query\Order;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\Cascade;
use Prism\Orm\Mapping\Fetch;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Mapping\LifecycleEvent;
use Prism\Orm\Mapping\OnDelete;
use Prism\Orm\Mapping\TrackingPolicy;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\Metadata\BehaviorMapping;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\Naming\DefaultNamingStrategy;
use Prism\Orm\Metadata\TenancyMapping;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Client;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\InvoiceLine;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\InvoiceListener;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Tag;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Inferred;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Person;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Priority;
use Prism\Orm\Tests\Unit\Tools\Fixtures\Ledger;
use Prism\Orm\Vector\Distance;

final class AttributeDriverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/InvalidEntities.php';

        // invalides au point que l'analyse statique les refuse : déclarées hors de sa portée
        if (!class_exists('Prism\\Orm\\Tests\\Unit\\Metadata\\Fixtures\\Invalid\\Untyped', false)) {
            eval(<<<'PHP'
                namespace Prism\Orm\Tests\Unit\Metadata\Fixtures\Invalid;

                use Prism\Orm\Mapping as Prism;

                #[Prism\Entity]
                final class Untyped
                {
                    #[Prism\Id]
                    public int $id = 0;
                    #[Prism\Column]
                    public $value;
                }

                #[Prism\Entity]
                final class UntypedRelation
                {
                    #[Prism\Id]
                    public int $id = 0;
                    #[Prism\ManyToOne]
                    public $client;
                }

                #[Prism\Entity]
                final class MissingTarget
                {
                    #[Prism\Id]
                    public int $id = 0;
                    #[Prism\OneToMany(targetEntity: 'App\Missing\Line', mappedBy: 'owner')]
                    public iterable $lines = [];
                }

                #[Prism\Entity(repositoryClass: 'App\Missing\Repository')]
                final class MissingRepository
                {
                    #[Prism\Id]
                    public int $id = 0;
                }
                PHP);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return ClassMetadata<T>
     */
    private static function load(string $class): ClassMetadata
    {
        return new AttributeDriver()->loadMetadata($class);
    }

    /**
     * @return array{string, bool, ?string}
     */
    private static function describe(FieldMapping $field): array
    {
        return [$field->type, $field->nullable, $field->enumType];
    }

    // RG-02.1 et RG-02.3
    public function testTypesAndNullabilityAreInferredFromPhpTypes(): void
    {
        $metadata = self::load(Inferred::class);

        self::assertSame(['integer', false, null], self::describe($metadata->getField('id')), 'generated ?int id stays NOT NULL');
        self::assertSame(['string', false, null], self::describe($metadata->getField('name')));
        self::assertSame(['string', true, null], self::describe($metadata->getField('nickname')));
        self::assertSame(['integer', false, null], self::describe($metadata->getField('quantity')));
        self::assertSame(['boolean', false, null], self::describe($metadata->getField('active')));
        self::assertSame(['float', false, null], self::describe($metadata->getField('ratio')));
        self::assertSame(['datetime_immutable', false, null], self::describe($metadata->getField('createdAt')));
        self::assertSame(['json', false, null], self::describe($metadata->getField('tags')));
        self::assertSame(['string', false, FixtureStatus::class], self::describe($metadata->getField('status')));
        self::assertSame(['integer', false, Priority::class], self::describe($metadata->getField('priority')));
        self::assertSame(['text', false, null], self::describe($metadata->getField('bio')));
    }

    public function testColumnNamesAndOptions(): void
    {
        $metadata = self::load(Inferred::class);

        self::assertSame('inferred', $metadata->tableName);
        self::assertSame('created_at', $metadata->getField('createdAt')->columnName);
        self::assertSame('biography', $metadata->getField('bio')->columnName);
        self::assertSame(1, $metadata->getField('priority')->default, 'a backed enum default is stored as its value');

        $amount = $metadata->getField('amount');
        self::assertSame([10, 2, true, true], [$amount->precision, $amount->scale, $amount->unique, $amount->sensitive]);
        $generated = $metadata->getField('generated');
        self::assertSame([3, false, false], [$generated->length, $generated->insertable, $generated->updatable]);
        self::assertSame('version', $metadata->versionField);
        self::assertSame(GenerationStrategy::Auto, $metadata->id->strategy);
    }

    // RG-02.6 et RG-02.7
    public function testReadonlyAndUnmappedProperties(): void
    {
        $metadata = self::load(Inferred::class);

        self::assertTrue($metadata->getField('code')->readonly);
        self::assertSame(12, $metadata->getField('code')->length);
        self::assertFalse($metadata->getField('name')->readonly);
        self::assertFalse($metadata->hasField('cache'));
        self::assertFalse($metadata->hasField('ignoredStatic'));
    }

    // RG-02.5
    public function testEmbeddables(): void
    {
        $metadata = self::load(Person::class);
        [$home, $work] = $metadata->getEmbedded();

        self::assertSame('people', $metadata->tableName);
        self::assertSame(['home', Address::class, 'home_', false], [$home->fieldName, $home->class, $home->columnPrefix, $home->nullable]);
        self::assertSame(['home_street', 'home_zip', 'home_city'], array_map(static fn (FieldMapping $f): string => $f->columnName, $home->getFields()));
        self::assertFalse($home->getField('city')->nullable);
        self::assertTrue($home->getField('city')->readonly);

        self::assertSame(['work', '', true], [$work->fieldName, $work->columnPrefix, $work->nullable]);
        self::assertSame(['street', 'zip', 'city'], array_map(static fn (FieldMapping $f): string => $f->columnName, $work->getFields()));
        self::assertTrue($work->getField('city')->nullable, 'a nullable embeddable has nullable columns');
        self::assertNull($metadata->id->strategy);
    }

    // la fixture qui utilise les 33 attributs, de bout en bout
    public function testInvoiceFixture(): void
    {
        $metadata = self::load(Invoice::class);

        self::assertSame(['invoice', TrackingPolicy::Explicit, ['engine' => 'InnoDB']], [$metadata->tableName, $metadata->trackingPolicy, $metadata->tableOptions]);
        self::assertSame([InvoiceListener::class], $metadata->entityListeners);
        self::assertSame(['onOtherEvents'], $metadata->getLifecycleCallbacks(LifecycleEvent::PostUpdate));
        self::assertSame(['onPrePersist'], $metadata->getLifecycleCallbacks(LifecycleEvent::PrePersist));
        self::assertSame('billing_city', $metadata->getField('billingAddress.city')->columnName);
        self::assertTrue($metadata->getField('billingAddress.city')->nullable);
        self::assertSame(['vector', true], [$metadata->getField('embedding')->type, $metadata->getField('embedding')->lazy]);

        $indexes = array_map(static fn ($i): array => [$i->fields, $i->unique, $i->where, $i->kind->name], $metadata->indexes);
        self::assertSame([
            [['issuedAt'], false, null, 'Regular'],
            [['status', 'issuedAt'], false, "status <> 'draft'", 'Regular'],
            [['number'], true, null, 'Regular'],
            [['number', 'notes'], false, null, 'FullText'],
            [['embedding'], false, null, 'Vector'],
        ], $indexes);
        // ADR 0017 : les options des deux sortes du pilier 4
        self::assertSame('french', $metadata->indexes[3]->language);
        self::assertSame([Distance::Cosine, 16, 64], [$metadata->indexes[4]->distance, $metadata->indexes[4]->m, $metadata->indexes[4]->efConstruction]);
        // ADR 0018 : deux colonnes d'état nommées d'après celle du vecteur, le fournisseur par défaut
        $embedding = $metadata->getEmbedding('embedding');
        self::assertNotNull($embedding);
        self::assertSame([['number', 'notes'], 'default', 'embedding_stale', 'embedding_model'], [$embedding->source, $embedding->provider, $embedding->staleColumn, $embedding->modelColumn]);
        self::assertNull($metadata->getEmbedding('number'));

        $client = $metadata->getAssociation('client');
        self::assertSame([AssociationType::ManyToOne, Client::class, 'client_id', false, 'invoices', [Cascade::Persist], 200], [$client->type, $client->targetEntity, $client->joinColumn, $client->nullable, $client->inversedBy, $client->cascade, $client->batchSize]);

        $creditNote = $metadata->getAssociation('creditNote');
        self::assertSame([AssociationType::OneToOne, Invoice::class, 'credit_note_id', true, OnDelete::SetNull], [$creditNote->type, $creditNote->targetEntity, $creditNote->joinColumn, $creditNote->nullable, $creditNote->onDelete]);

        $lines = $metadata->getAssociation('lines');
        self::assertSame([AssociationType::OneToMany, InvoiceLine::class, 'invoice', true, ['position' => Order::Asc]], [$lines->type, $lines->targetEntity, $lines->mappedBy, $lines->orphanRemoval, $lines->orderBy]);
        self::assertFalse($lines->isOwningSide());

        $tags = $metadata->getAssociation('tags');
        self::assertNotNull($tags->joinTable);
        self::assertSame(['invoice_tag', 'invoice_id', 'tag_id', OnDelete::Cascade, false], [$tags->joinTable->name, $tags->joinTable->joinColumn, $tags->joinTable->inverseJoinColumn, $tags->joinTable->onDelete, $tags->batch]);
    }

    public function testInverseSidesAndOtherOptions(): void
    {
        $client = self::load(Client::class)->getAssociation('invoices');
        self::assertSame([Fetch::ExtraLazy, null], [$client->fetch, $client->joinTable]);

        $tag = self::load(Tag::class);
        self::assertTrue($tag->readOnly);
        self::assertSame([GenerationStrategy::Sequence, 'tag_seq'], [$tag->id->strategy, $tag->id->sequenceName]);
        self::assertFalse($tag->getAssociation('invoices')->isOwningSide());

        $line = self::load(InvoiceLine::class)->getAssociation('invoice');
        self::assertSame(['invoice_id', OnDelete::Cascade, false], [$line->joinColumn, $line->onDelete, $line->nullable]);
    }

    public function testNamingStrategyIsUsedForEveryGeneratedName(): void
    {
        $metadata = new AttributeDriver([], new DefaultNamingStrategy())->loadMetadata(Invoice::class);

        self::assertSame('issuedAt', $metadata->getField('issuedAt')->columnName);
        self::assertSame('client_id', $metadata->getAssociation('client')->joinColumn);
        self::assertSame('InvoiceLine', new AttributeDriver([], new DefaultNamingStrategy())->loadMetadata(InvoiceLine::class)->tableName);
    }

    // ADR 0015 : les quatre comportements, lus avec la metadata et mis en cache avec elle
    public function testBehaviorsAreReadFromTheAttributes(): void
    {
        $driver = new AttributeDriver();
        $invoice = $driver->loadMetadata(Invoice::class);

        self::assertEquals(new BehaviorMapping('createdAt', 'updatedAt', 'createdBy', 'updatedBy', 'deletedAt', true, ['updatedAt'], 'P10Y'), $invoice->behaviors);
        self::assertSame(['updatedAt', 'updatedBy'], $invoice->behaviors->updateFields());
        self::assertEquals(new BehaviorMapping(), $driver->loadMetadata(Client::class)->behaviors);
        self::assertFalse($driver->loadMetadata(Client::class)->behaviors->isSoftDeletable());
        self::assertEquals($invoice, unserialize(serialize($invoice)));
    }

    // RG-08.1, ADR 0014
    public function testTenancyIsReadFromTheAttribute(): void
    {
        $driver = new AttributeDriver();

        self::assertEquals(new TenancyMapping('tenant_id'), $driver->loadMetadata(Client::class)->tenancy);
        self::assertEquals(new TenancyMapping('tenant_id', global: true), $driver->loadMetadata(Tag::class)->tenancy);
        self::assertEquals(new TenancyMapping('company_id', field: 'companyId'), $driver->loadMetadata(Ledger::class)->tenancy, 'a property mapped on the tenant column');
        self::assertNull($driver->loadMetadata(Person::class)->tenancy);

        self::assertSame('uuid', new AttributeDriver(tenantType: 'uuid')->loadMetadata(Client::class)->tenancy?->type, 'the type of the EntityManager');
        self::assertSame('tenantId', new AttributeDriver([], new DefaultNamingStrategy())->loadMetadata(Client::class)->tenancy?->column);

        $metadata = $driver->loadMetadata(Ledger::class);
        self::assertEquals($metadata, unserialize(serialize($metadata)), 'cached with the metadata');
    }

    public function testEntitiesAreDiscoveredWithoutExecutingFiles(): void
    {
        $driver = new AttributeDriver([__DIR__ . '/../Mapping/Fixtures', __DIR__ . '/missing-directory']);

        self::assertSame([Client::class, Invoice::class, InvoiceLine::class, Tag::class], $driver->getAllClassNames());
        self::assertTrue($driver->isEntity(Invoice::class));
        self::assertFalse($driver->isEntity(Address::class));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidEntities(): iterable
    {
        $ns = 'Prism\Orm\Tests\Unit\Metadata\Fixtures\Invalid\\';

        // RG-02.2
        yield 'mutable DateTime' => [$ns . 'MutableDate', 'MutableDate::$at: \DateTime is mutable and not supported, use \DateTimeImmutable instead.'];
        yield 'mutable DateTime with explicit type' => [$ns . 'MutableDateWithExplicitType', '\DateTime is mutable and not supported, use \DateTimeImmutable instead.'];
        yield 'union type' => [$ns . 'UnionType', 'UnionType::$value: the column type cannot be inferred from the union or intersection type "string|int"'];
        yield 'untyped' => [$ns . 'Untyped', 'Untyped::$value: the column type cannot be inferred without a PHP type'];
        yield 'object type' => [$ns . 'ObjectType', 'ObjectType::$value: the column type cannot be inferred from PHP type "stdClass"'];
        // RG-02.3, dans les deux sens
        yield 'nullable false on ?string' => [$ns . 'NotNullOnNullable', 'NotNullOnNullable::$value: #[Column(nullable: false)] contradicts the nullable PHP type "?string"'];
        yield 'nullable true on string' => [$ns . 'NullableOnNotNull', 'NullableOnNotNull::$value: #[Column(nullable: true)] cannot be hydrated into the non-nullable PHP type "string"; make it "?string".'];
        yield 'nullable identifier' => [$ns . 'NullableId', 'NullableId::$id: the identifier cannot be nullable.'];
        // RG-02.4
        yield 'no identifier' => [$ns . 'NoId', 'NoId: an entity needs exactly one #[Id] property.'];
        yield 'two identifiers' => [$ns . 'TwoIds', 'TwoIds: only one #[Id] is supported (found $a and $b); composite keys are out of scope for 1.0.'];
        yield 'callback without flag' => [$ns . 'CallbackWithoutFlag', 'method touch() has a lifecycle callback attribute but the class lacks #[HasLifecycleCallbacks].'];
        yield 'embedded class without #[Embeddable]' => [$ns . 'EmbeddedNotEmbeddable', 'EmbeddedNotEmbeddable::$value: ArrayObject is not an embeddable'];
        yield 'nested embeddable' => [$ns . 'NestedEmbeddable', 'OuterEmbeddable::$inner: nested embeddables are not supported.'];
        yield 'mapped parent' => [$ns . 'ChildOfMappedParent', 'maps property $inherited; entity inheritance is out of scope for 1.0.'];
        yield 'non scalar default' => [$ns . 'BadDefault', 'BadDefault::$value: a column default must be a scalar or a backed enum case, got array.'];
        yield 'untyped relation' => [$ns . 'UntypedRelation', 'UntypedRelation::$client: the target entity cannot be determined'];
        yield 'missing target' => [$ns . 'MissingTarget', 'MissingTarget::$lines: the target entity App\Missing\Line does not exist.'];
        yield 'missing repository' => [$ns . 'MissingRepository', 'MissingRepository: repository class App\Missing\Repository does not exist.'];
        yield 'not an entity' => [$ns . 'NotAnEntity', 'NotAnEntity is not an entity: add #[Entity] to the class.'];
        yield 'unknown class' => [$ns . 'DoesNotExist', 'DoesNotExist does not exist or cannot be autoloaded.'];
        // ADR 0014 : reporté en 1.x
        // RG-09.6 : un ghost ne peut être qu'un Vector
        yield 'lazy list of floats' => [$ns . 'LazyArrayVector', 'LazyArrayVector::$embedding: lazy: true needs a property typed Prism\Orm\Vector\Vector; a "array" property is always loaded.'];
        yield 'Vector on a text column' => [$ns . 'VectorAsText', 'VectorAsText::$embedding: a Prism\Orm\Vector\Vector property maps a "vector" column, not "text".'];
        yield 'tenant strict foreign keys' => [$ns . 'StrictTenantForeignKeys', 'StrictTenantForeignKeys: #[Tenanted(strictForeignKeys: true)] is not supported in 1.0: it needs composite foreign keys on (tenant_id, id). Remove it; relations between tenants are refused at flush.'];
    }

    #[DataProvider('invalidEntities')]
    public function testInvalidEntitiesAreRefusedWithAPreciseMessage(string $class, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        /** @var class-string $class */
        new AttributeDriver()->loadMetadata($class);
    }

    // étape 7 : méthodes des listeners lues avec la metadata, pour le cache
    public function testEntityListenerMethodsAreReadWithTheMetadata(): void
    {
        $metadata = new AttributeDriver()->loadMetadata(\Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Journal::class);
        $listener = \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\JournalListener::class;

        self::assertSame([$listener], $metadata->entityListeners);
        self::assertSame(['prePersist', 'postPersist', 'preUpdate', 'postUpdate', 'preRemove', 'postRemove', 'postLoad'], array_keys($metadata->entityListenerCallbacks[$listener]));
        self::assertSame([[$listener, 'record']], $metadata->getEntityListenerCallbacks(LifecycleEvent::PostLoad));
        self::assertSame(['record', 'touch'], $metadata->getLifecycleCallbacks(LifecycleEvent::PrePersist));
        self::assertEquals($metadata, unserialize(serialize($metadata)), 'cached with the metadata');
    }

    // régression : une auto-référence typée ?self vise sa propre classe
    public function testSelfTypedRelationTargetsItsOwnClass(): void
    {
        $category = \Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations\Category::class;

        self::assertSame($category, new AttributeDriver()->loadMetadata($category)->getAssociation('parent')->targetEntity);
    }
}
