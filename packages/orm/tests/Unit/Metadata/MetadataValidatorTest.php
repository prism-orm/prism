<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\Cascade;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Mapping\OnDelete;
use Prism\Orm\Mapping\Order;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\Metadata\BehaviorMapping;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\EmbeddedMapping;
use Prism\Orm\Metadata\EmbeddingMapping;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\IdMapping;
use Prism\Orm\Metadata\IndexMapping;
use Prism\Orm\Metadata\JoinTableMapping;
use Prism\Orm\Metadata\MappingError;
use Prism\Orm\Metadata\MappingSeverity;
use Prism\Orm\Metadata\MetadataValidator;
use Prism\Orm\Metadata\TenancyMapping;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Client;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\InvoiceLine;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Tag;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Callbacks\CallbackEntity;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Callbacks\CheckedListener;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Callbacks\EmptyListener;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Callbacks\ListenedEntity;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Callbacks\UntypedCollections;
use Prism\Orm\Vector\Distance;
use Prism\Orm\Vector\EmbeddingProviderRegistry;
use Prism\Orm\Vector\HashingEmbeddingProvider;

/**
 * Cas du validateur, chacun avec son message exact. Avec les 20 erreurs du driver, ils forment
 * les 40 cas du critère de sortie de la phase 2.
 */
final class MetadataValidatorTest extends TestCase
{
    /**
     * @template T of object
     *
     * @param class-string<T>          $class
     * @param list<FieldMapping>       $fields
     * @param list<AssociationMapping> $associations
     * @param list<EmbeddedMapping>    $embedded
     * @param list<IndexMapping>       $indexes
     *
     * @return ClassMetadata<T>
     */
    private static function meta(string $class, string $table, array $fields = [], array $associations = [], array $embedded = [], array $indexes = [], ?string $version = null, ?GenerationStrategy $strategy = null, ?FieldMapping $id = null, ?TenancyMapping $tenancy = null, ?BehaviorMapping $behaviors = null): ClassMetadata
    {
        return new ClassMetadata($class, $table, new IdMapping('id', $strategy), [$id ?? new FieldMapping('id', 'id', 'integer'), ...$fields], $embedded, $associations, $indexes, $version, tenancy: $tenancy, behaviors: $behaviors);
    }

    private static function toOne(string $class, string $field, string $target, ?string $inversedBy = null, AssociationType $type = AssociationType::ManyToOne, bool $nullable = true, OnDelete $onDelete = OnDelete::Restrict, ?string $joinColumn = null): AssociationMapping
    {
        /** @var class-string $class */
        /** @var class-string $target */
        return new AssociationMapping($class, $field, $type, $target, null, $inversedBy, $joinColumn ?? $field . '_id', $nullable, $onDelete);
    }

    /**
     * @param array<string, Order> $orderBy
     */
    private static function oneToMany(string $class, string $field, string $target, string $mappedBy, array $orderBy = [], ?string $indexBy = null): AssociationMapping
    {
        /** @var class-string $class */
        /** @var class-string $target */
        return new AssociationMapping($class, $field, AssociationType::OneToMany, $target, $mappedBy, indexBy: $indexBy, orderBy: $orderBy);
    }

    private static function manyToMany(string $class, string $field, string $target, ?string $inversedBy = null, string $joinTable = 'pivot'): AssociationMapping
    {
        /** @var class-string $class */
        /** @var class-string $target */
        return new AssociationMapping($class, $field, AssociationType::ManyToMany, $target, inversedBy: $inversedBy, joinTable: new JoinTableMapping($joinTable, 'a_id', 'b_id'));
    }

    /**
     * @param list<ClassMetadata<object>> $metadata
     *
     * @return list<string>
     */
    private static function errors(array $metadata, ?TypeRegistry $types = null): array
    {
        return array_map(static fn (MappingError $e): string => (string) $e, new MetadataValidator($types)->validate($metadata));
    }

    /**
     * @return iterable<string, array{\Closure(): list<ClassMetadata<object>>, list<string>}>
     */
    public static function cases(): iterable
    {
        require_once __DIR__ . '/Fixtures/CallbackClasses.php';
        $client = static fn (): ClassMetadata => self::meta(Client::class, 'client');
        $tag = static fn (): ClassMetadata => self::meta(Tag::class, 'tag');

        // colonnes et tables (RG-02.8)
        yield '1 duplicate column' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('a', 'name', 'string'), new FieldMapping('b', 'NAME', 'string')])],
            [Client::class . '::$b: column "NAME" is already used by $a.'],
        ];
        yield '2 field and embeddable column' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('city', 'home_city', 'string')], [], [new EmbeddedMapping('home', Address::class, 'home_', false, [new FieldMapping('city', 'home_city', 'string')])])],
            [Client::class . '::$home.city: column "home_city" is already used by $city.'],
        ];
        yield '3 join column and field column' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [new FieldMapping('clientId', 'client_id', 'integer')], [self::toOne(Invoice::class, 'client', Client::class)]), $client()],
            [Invoice::class . '::$client: column "client_id" is already used by $clientId.'],
        ];
        yield '4 two entities on one table' => [
            static fn (): array => [self::meta(Invoice::class, 'shared'), self::meta(Client::class, 'SHARED')],
            [Client::class . ': table "SHARED" is already used by ' . Invoice::class . '.'],
        ];
        yield '5 join table named like an entity table' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [], [self::manyToMany(Invoice::class, 'tags', Tag::class, joinTable: 'tag')]), $tag()],
            [Invoice::class . '::$tags: join table "tag" has the same name as the table of ' . Tag::class . '.'],
        ];
        yield '6 identifier too long' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('a', str_repeat('c', 64), 'string')])],
            [Client::class . '::$a: column name "' . str_repeat('c', 64) . '" is 64 bytes long; the maximum is 63 (PostgreSQL would silently truncate it).'],
        ];

        // types
        yield '7 unknown type' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('balance', 'balance', 'money')])],
            [Client::class . '::$balance: unknown type "money"; registered types: string, text, integer, smallint, bigint, boolean, decimal, float, datetime_immutable, date_immutable, time_immutable, json, uuid, binary, vector. Register custom types with TypeRegistry::register().'],
        ];
        yield '8 enum backing type' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('status', 'status', 'integer', enumType: FixtureStatus::class)])],
            [Client::class . '::$status: enum ' . FixtureStatus::class . ' is backed by string and cannot be stored in a "integer" column.'],
        ];
        yield '9 decimal without precision' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('total', 'total', 'decimal')])],
            [Client::class . "::\$total: a decimal column needs a precision and a scale, for example #[Column(type: 'decimal', precision: 12, scale: 2)]."],
        ];
        yield '10a length on an integer' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('qty', 'qty', 'integer', length: 4)])],
            [Client::class . '::$qty: length is not supported by the "integer" type.'],
        ];
        yield '10b precision on a string' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('code', 'code', 'string', precision: 4, scale: 0)])],
            [Client::class . '::$code: precision and scale are only supported by the "decimal" type, not "string".'],
        ];
        yield '10c fixed on an integer' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('qty', 'qty', 'integer', fixed: true)])],
            [Client::class . '::$qty: fixed needs a "string" or "binary" column with a length, for example #[Column(length: 3, fixed: true)]; got "integer" without length.'],
        ];
        yield '10d fixed without length' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('code', 'code', 'string', fixed: true)])],
            [Client::class . '::$code: fixed needs a "string" or "binary" column with a length, for example #[Column(length: 3, fixed: true)]; got "string" without length.'],
        ];
        yield '11 default of the wrong type' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('qty', 'qty', 'integer', default: 'abc')])],
            [Client::class . "::\$qty: the default value 'abc' does not match the \"integer\" type."],
        ];
        yield '12 default on text' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('notes', 'notes', 'text', default: '-')])],
            [Client::class . '::$notes: a "text" column cannot have a default value (MySQL refuses it).'],
        ];

        // identifiant et version (RG-02.4, RG-03.9)
        yield '13 UuidV7 on an integer' => [
            static fn (): array => [self::meta(Client::class, 'client', strategy: GenerationStrategy::UuidV7)],
            [Client::class . '::$id: GenerationStrategy::UuidV7 needs a "uuid" column, not "integer".'],
        ];
        yield '14 Auto on a string' => [
            static fn (): array => [self::meta(Client::class, 'client', strategy: GenerationStrategy::Auto, id: new FieldMapping('id', 'id', 'string'))],
            [Client::class . '::$id: GenerationStrategy::Auto needs an integer, smallint or bigint column, not "string"; use UuidV7 for a uuid.'],
        ];
        yield '15 version of a wrong type' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('version', 'version', 'string')], version: 'version')],
            [Client::class . '::$version: a #[Version] field must be an integer, smallint, bigint or datetime_immutable column, not "string".'],
        ];
        yield '16 nullable version' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('version', 'version', 'integer', nullable: true)], version: 'version')],
            [Client::class . '::$version: a #[Version] field cannot be nullable.'],
        ];

        // relations (RG-02.8, RG-04.6)
        yield '17a target is not an entity' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [], [self::toOne(Invoice::class, 'address', Address::class)])],
            [Invoice::class . '::$address: the target ' . Address::class . ' is not an entity.'],
        ];
        yield '17b target entity not mapped' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [], [self::toOne(Invoice::class, 'client', Client::class)])],
            [Invoice::class . '::$client: the target ' . Client::class . ' is not among the mapped entities; add its directory to the metadata sources.'],
        ];
        yield '18 mappedBy on a missing relation' => [
            static fn (): array => [self::meta(Client::class, 'client', [], [self::oneToMany(Client::class, 'invoices', Invoice::class, 'owner')]), self::meta(Invoice::class, 'invoice')],
            [Client::class . '::$invoices: mappedBy "owner" is not a relation of ' . Invoice::class . '.'],
        ];
        yield '19 mappedBy on a relation to another entity' => [
            static fn (): array => [self::meta(Client::class, 'client', [], [self::oneToMany(Client::class, 'invoices', Invoice::class, 'owner')]), self::meta(Invoice::class, 'invoice', [], [self::toOne(Invoice::class, 'owner', Tag::class)]), $tag()],
            [Client::class . '::$invoices: mappedBy "owner" targets ' . Tag::class . ', not ' . Client::class . '.'],
        ];
        yield '20a not paired back' => [
            static fn (): array => [self::meta(Client::class, 'client', [], [self::oneToMany(Client::class, 'invoices', Invoice::class, 'client')]), self::meta(Invoice::class, 'invoice', [], [self::toOne(Invoice::class, 'client', Client::class)])],
            [Client::class . '::$invoices: mappedBy "client" is not paired back: ' . Invoice::class . "::\$client must declare inversedBy: 'invoices'."],
        ];
        yield '20b both sides owning' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [], [self::manyToMany(Invoice::class, 'tags', Tag::class, 'invoices', 'invoice_tag')]), self::meta(Tag::class, 'tag', [], [self::manyToMany(Tag::class, 'invoices', Invoice::class, 'tags', 'tag_invoice')])],
            [
                Invoice::class . '::$tags: inversedBy "invoices" is not paired back: ' . Tag::class . "::\$invoices must declare mappedBy: 'tags'.",
                Tag::class . '::$invoices: inversedBy "tags" is not paired back: ' . Invoice::class . "::\$tags must declare mappedBy: 'invoices'.",
            ],
        ];
        yield '21 incompatible relation types' => [
            static fn (): array => [self::meta(Client::class, 'client', [], [self::oneToMany(Client::class, 'invoices', Invoice::class, 'client')]), self::meta(Invoice::class, 'invoice', [], [self::toOne(Invoice::class, 'client', Client::class, 'invoices', AssociationType::OneToOne)])],
            [
                Client::class . '::$invoices: a OneToMany cannot be paired with the OneToOne ' . Invoice::class . '::$client; expected a ManyToOne.',
                Invoice::class . '::$client: a OneToOne cannot be paired with the OneToMany ' . Client::class . '::$invoices; expected a OneToOne.',
            ],
        ];
        yield '22 SetNull on a non nullable relation' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [], [self::toOne(Invoice::class, 'client', Client::class, nullable: false, onDelete: OnDelete::SetNull)]), $client()],
            [Invoice::class . '::$client: onDelete: SetNull needs a nullable relation; make the property nullable or choose another action.'],
        ];
        yield '23a orderBy on an unknown field' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [], [self::oneToMany(Invoice::class, 'lines', InvoiceLine::class, 'invoice', ['position' => Order::Asc])]), self::meta(InvoiceLine::class, 'line', [], [self::toOne(InvoiceLine::class, 'invoice', Invoice::class, 'lines')])],
            [Invoice::class . '::$lines: orderBy uses "position", which is not a field of ' . InvoiceLine::class . '.'],
        ];
        yield '23b indexBy on an unknown field' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [], [self::oneToMany(Invoice::class, 'lines', InvoiceLine::class, 'invoice', indexBy: 'sku')]), self::meta(InvoiceLine::class, 'line', [], [self::toOne(InvoiceLine::class, 'invoice', Invoice::class, 'lines')])],
            [Invoice::class . '::$lines: indexBy uses "sku", which is not a field of ' . InvoiceLine::class . '.'],
        ];

        // index
        yield '24 index on an unknown field' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [new FieldMapping('number', 'number', 'string')], [self::toOne(Invoice::class, 'client', Client::class)], [], [new IndexMapping(['client', 'number']), new IndexMapping(['missing'], true)]), $client()],
            [Invoice::class . ': #[UniqueConstraint] on "missing": "missing" is not a mapped field or an owning to-one relation.'],
        ];

        // callbacks et listeners d'entité (étape 7 de la phase 3)
        yield '25 lifecycle callback signatures' => [
            static fn (): array => [new ClassMetadata(CallbackEntity::class, 'callback_entity', new IdMapping('id'), [new FieldMapping('id', 'id', 'integer')], lifecycleCallbacks: [
                'prePersist' => ['noArgument', 'anyObject', 'union', 'tooMany', 'shared', 'hidden'],
                'postLoad' => ['wrongType'],
            ])],
            [
                CallbackEntity::class . ': lifecycle callback tooMany() takes at most one argument: the event.',
                CallbackEntity::class . ': lifecycle callback shared() must be a public, non-static method.',
                CallbackEntity::class . ': lifecycle callback hidden() must be a public, non-static method.',
                CallbackEntity::class . ': lifecycle callback wrongType(): parameter $event must accept Prism\\Orm\\Event\\PostLoadEvent, which #[PostLoad] passes.',
            ],
        ];
        yield '27 collection relations typed Collection' => [
            static fn (): array => [self::meta(UntypedCollections::class, 'untyped', [], [
                self::manyToMany(UntypedCollections::class, 'iterable', Tag::class, joinTable: 'pivot_iterable'),
                self::manyToMany(UntypedCollections::class, 'array', Tag::class, joinTable: 'pivot_array'),
                self::manyToMany(UntypedCollections::class, 'nullable', Tag::class, joinTable: 'pivot_nullable'),
                self::manyToMany(UntypedCollections::class, 'typed', Tag::class, joinTable: 'pivot_typed'),
            ]), self::meta(Tag::class, 'tag')],
            array_map(static fn (string $field): string => UntypedCollections::class . '::$' . $field . ': a collection relation must be typed Prism\\Orm\\Collection\\Collection (not array, iterable or nullable), initialized with new ArrayCollection() in the constructor (ADR 0007).', ['iterable', 'array', 'nullable']),
        ];
        // colonne du tenant (RG-08.1, ADR 0014)
        yield '28a unsupported tenant type' => [
            static fn (): array => [self::meta(Client::class, 'client', tenancy: new TenancyMapping('tenant_id', 'string'))],
            [Client::class . ': the tenant type "string" is not supported; use one of: integer, bigint, uuid.'],
        ];
        yield '28b tenant column already used' => [
            static fn (): array => [self::meta(Invoice::class, 'invoice', [], [self::toOne(Invoice::class, 'tenant', Client::class)], tenancy: new TenancyMapping('tenant_id')), $client()],
            [Invoice::class . ': the #[Tenanted] column "tenant_id" is already used by $tenant; rename one of them (#[Tenanted(column: ...)]).'],
        ];
        yield '28c tenant column too long' => [
            static fn (): array => [self::meta(Client::class, 'client', tenancy: new TenancyMapping(str_repeat('t', 64)))],
            [Client::class . ': column name "' . str_repeat('t', 64) . '" is 64 bytes long; the maximum is 63 (PostgreSQL would silently truncate it).'],
        ];
        yield '28d tenant property of another type' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('tenantId', 'tenant_id', 'string')], tenancy: new TenancyMapping('tenant_id', field: 'tenantId'))],
            [Client::class . '::$tenantId: the #[Tenanted] column "tenant_id" has type "string", but the tenant type is "integer".'],
        ];
        yield '28e nullable tenant property' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('tenantId', 'tenant_id', 'integer', true)], tenancy: new TenancyMapping('tenant_id', field: 'tenantId'))],
            [Client::class . '::$tenantId: the #[Tenanted] column "tenant_id" is never NULL; make the property non-nullable, or use #[Tenanted(global: true)] for shared rows.'],
        ];
        yield '28f non-nullable global tenant property' => [
            static fn (): array => [self::meta(Tag::class, 'tag', [new FieldMapping('tenantId', 'tenant_id', 'integer')], tenancy: new TenancyMapping('tenant_id', global: true, field: 'tenantId'))],
            [Tag::class . '::$tenantId: the #[Tenanted(global: true)] column "tenant_id" holds NULL for shared rows; make the property nullable.'],
        ];
        yield '28g identifier as tenant column' => [
            static fn (): array => [self::meta(Client::class, 'client', tenancy: new TenancyMapping('id', field: 'id'))],
            [Client::class . '::$id: the identifier cannot be the #[Tenanted] column "id".'],
        ];
        // comportements (ADR 0015)
        yield '29a behavior on an unknown field' => [
            static fn (): array => [self::meta(Client::class, 'client', behaviors: new BehaviorMapping(createdAt: 'created'))],
            [Client::class . ': #[Timestampable] names "created", which is not a mapped field.'],
        ];
        yield '29b timestamp of another type' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('createdAt', 'created_at', 'string')], behaviors: new BehaviorMapping(createdAt: 'createdAt'))],
            [Client::class . '::$createdAt: #[Timestampable] needs a "datetime_immutable" field, not "string".'],
        ];
        yield '29c non-nullable author' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('createdBy', 'created_by', 'string')], behaviors: new BehaviorMapping(createdBy: 'createdBy'))],
            [Client::class . '::$createdBy: #[Blameable] needs a nullable field.'],
        ];
        yield '29d non-nullable deletion date' => [
            static fn (): array => [self::meta(Client::class, 'client', [new FieldMapping('deletedAt', 'deleted_at', 'datetime_immutable')], behaviors: new BehaviorMapping(deletedAt: 'deletedAt'))],
            [Client::class . '::$deletedAt: #[SoftDeletable] needs a nullable field.'],
        ];
        yield '29e audit excludes an unknown field' => [
            static fn (): array => [self::meta(Client::class, 'client', behaviors: new BehaviorMapping(auditable: true, auditExclude: ['missing']))],
            [Client::class . ': #[Auditable] excludes "missing", which is not a mapped field or relation.'],
        ];
        // une interface n'est pas une classe instanciable
        $missing = \Countable::class;
        yield '26 entity listener signatures' => [
            static fn (): array => [new ClassMetadata(ListenedEntity::class, 'listened_entity', new IdMapping('id'), [new FieldMapping('id', 'id', 'integer')], entityListeners: [$missing, EmptyListener::class, CheckedListener::class], entityListenerCallbacks: [
                $missing => [],
                EmptyListener::class => [],
                CheckedListener::class => ['prePersist' => ['valid', 'wrongEntity', 'wrongEvent', 'tooMany', 'hidden'], 'postLoad' => ['load']],
            ])],
            [
                ListenedEntity::class . ': entity listener Countable does not exist or is not a class.',
                ListenedEntity::class . ': entity listener ' . EmptyListener::class . ' has no method with a lifecycle attribute (#[PrePersist], #[PostLoad]…).',
                ListenedEntity::class . ': entity listener method ' . CheckedListener::class . '::wrongEntity(): parameter $entity must accept ' . ListenedEntity::class . ', which #[PrePersist] passes.',
                ListenedEntity::class . ': entity listener method ' . CheckedListener::class . '::wrongEvent(): parameter $event must accept Prism\\Orm\\Event\\PrePersistEvent, which #[PrePersist] passes.',
                ListenedEntity::class . ': entity listener method ' . CheckedListener::class . '::tooMany() takes at most two arguments: the entity and the event.',
                ListenedEntity::class . ': entity listener method ' . CheckedListener::class . '::hidden() must be a public, non-static method.',
            ],
        ];
    }

    /**
     * @param \Closure(): list<ClassMetadata<object>> $metadata
     * @param list<string>                            $expected
     */
    #[DataProvider('cases')]
    public function testEachCaseReportsExactlyItsErrors(\Closure $metadata, array $expected): void
    {
        self::assertSame($expected, self::errors($metadata()));
    }

    public function testValidMappingHasNoError(): void
    {
        $metadata = [
            self::meta(Client::class, 'client', [new FieldMapping('name', 'name', 'string', default: 'x')], [self::oneToMany(Client::class, 'invoices', Invoice::class, 'client')]),
            self::meta(Invoice::class, 'invoice', [new FieldMapping('version', 'version', 'integer'), new FieldMapping('total', 'total', 'decimal', default: '0.00', precision: 12, scale: 2)], [self::toOne(Invoice::class, 'client', Client::class, 'invoices', onDelete: OnDelete::SetNull)], [], [new IndexMapping(['client', 'total'])], 'version', GenerationStrategy::Identity),
        ];

        self::assertSame([], self::errors($metadata));
        new MetadataValidator()->assertValid($metadata);
    }

    // les fixtures complètes, type vector compris (phase 9) : valides, un seul avertissement
    public function testFixturesAreValid(): void
    {
        $driver = new AttributeDriver();
        $metadata = array_map($driver->loadMetadata(...), [Invoice::class, Client::class, InvoiceLine::class, Tag::class]);

        $found = new MetadataValidator()->validate($metadata);
        $errors = array_values(array_filter($found, static fn (MappingError $e): bool => MappingSeverity::Error === $e->severity));
        $warnings = array_values(array_filter($found, static fn (MappingError $e): bool => MappingSeverity::Warning === $e->severity));
        self::assertSame([], $errors);
        // RG-04.6 : Invoice::$lines (cascade remove, orphanRemoval) et InvoiceLine::$invoice (onDelete CASCADE)
        self::assertSame([Invoice::class . '::$lines: cascade: remove repeats onDelete: CASCADE of ' . InvoiceLine::class . '::$invoice: the database already deletes these rows, without events. Keep one of them (RG-04.6).'], array_map(static fn (MappingError $e): string => (string) $e, $warnings));

        // l'avertissement RG-04.6 reste, il ne bloque pas
        new MetadataValidator()->assertValid($metadata);
        self::assertCount(1, self::errors($metadata));
    }

    /**
     * RG-09.1, RG-09.3 (ADR 0017) : la dimension partout, puis ce que la plateforme visée refuse.
     *
     * @return iterable<string, array{list<FieldMapping>, list<IndexMapping>, AbstractPlatform|null, list<string>}>
     */
    public static function vectorCases(): iterable
    {
        $vector = new FieldMapping('embedding', 'embedding', 'vector', length: 3);
        $index = new IndexMapping(['embedding'], kind: IndexKind::Vector, distance: Distance::Cosine);
        $mariaDb = new MariaDbPlatform('11.8');

        yield 'valid without a platform' => [[$vector], [$index], null, []];
        yield 'valid on MariaDB' => [[$vector], [$index], $mariaDb, []];
        yield 'valid on SQLite' => [[$vector], [$index], new SqlitePlatform('3.46'), []];
        yield 'no dimensions' => [[new FieldMapping('embedding', 'embedding', 'vector')], [], null, [
            Client::class . "::\$embedding: a vector field needs its dimensions: #[Column(type: 'vector', dimensions: …)] (RG-09.1).",
        ]];
        yield 'MySQL' => [[$vector], [], new MySqlPlatform('8.4'), [
            Client::class . '::$embedding: MySQL does not support the vector type: it has neither a vector distance nor a vector index. Use MariaDB 11.8+ or PostgreSQL with pgvector 0.8+.',
        ]];
        yield 'inner product on MariaDB' => [[$vector], [new IndexMapping(['embedding'], kind: IndexKind::Vector, distance: Distance::InnerProduct)], $mariaDb, [
            Client::class . '::$embedding: MariaDB has no inner product distance; use Distance::Cosine or Distance::Euclidean (RG-09.3).',
        ]];
        yield 'inner product on PostgreSQL' => [[$vector], [new IndexMapping(['embedding'], kind: IndexKind::Vector, distance: Distance::InnerProduct)], new PostgreSqlPlatform('16'), []];
        yield 'nullable indexed vector on MariaDB' => [[new FieldMapping('embedding', 'embedding', 'vector', nullable: true, length: 3)], [$index], $mariaDb, [
            Client::class . '::$embedding: MariaDB only indexes NOT NULL vector columns; make the field non-nullable.',
        ]];
        yield 'two vector indexes on MariaDB' => [[$vector, new FieldMapping('other', 'other', 'vector', length: 3)], [$index, new IndexMapping(['other'], kind: IndexKind::Vector, distance: Distance::Cosine)], $mariaDb, [
            Client::class . ': MariaDB allows one vector index per table, 2 are declared on embedding, other.',
        ]];
        yield 'too many dimensions for HNSW on PostgreSQL' => [[new FieldMapping('embedding', 'embedding', 'vector', length: 3072)], [$index], new PostgreSqlPlatform('16'), [
            Client::class . '::$embedding: pgvector indexes vectors of up to 2000 dimensions with HNSW, the field has 3072.',
        ]];
        yield 'vector index on a string' => [[new FieldMapping('embedding', 'embedding', 'string')], [$index], null, [
            Client::class . '::$embedding: #[VectorIndex] needs a field of type "vector".',
        ]];
        yield 'full-text index on a number' => [[new FieldMapping('total', 'total', 'integer'), new FieldMapping('title', 'title', 'string')], [new IndexMapping(['title', 'total'], kind: IndexKind::FullText)], null, [
            Client::class . ': #[FullTextIndex] on "title, total": "total" is not a string or text field.',
        ]];
        yield 'full-text index on an unknown field' => [[], [new IndexMapping(['title'], kind: IndexKind::FullText)], null, [
            Client::class . ': #[FullTextIndex] on "title": "title" is not a mapped field or an owning to-one relation.',
        ]];
    }

    /**
     * @param list<FieldMapping> $fields
     * @param list<IndexMapping> $indexes
     * @param list<string>       $expected
     */
    #[DataProvider('vectorCases')]
    public function testVectorRulesPerPlatform(array $fields, array $indexes, ?AbstractPlatform $platform, array $expected): void
    {
        $errors = new MetadataValidator(platform: $platform)->validate([self::meta(Client::class, 'client', $fields, indexes: $indexes)]);

        self::assertSame($expected, array_map(static fn (MappingError $e): string => (string) $e, $errors));
    }

    /**
     * ADR 0018 : #[EmbeddedVector] sur un vecteur, des sources texte, des colonnes d'état libres,
     * un fournisseur connu de la même dimension.
     *
     * @return iterable<string, array{list<FieldMapping>, list<EmbeddingMapping>, EmbeddingProviderRegistry|null, list<string>}>
     */
    public static function embeddingCases(): iterable
    {
        $vector = new FieldMapping('embedding', 'embedding', 'vector', nullable: true, length: 8);
        $title = new FieldMapping('title', 'title', 'string');
        $embedding = new EmbeddingMapping('embedding', ['title'], 'default', 'embedding_stale', 'embedding_model');
        $providers = new EmbeddingProviderRegistry(['default' => new HashingEmbeddingProvider(8)]);

        yield 'valid without providers' => [[$vector, $title], [$embedding], null, []];
        yield 'valid with its provider' => [[$vector, $title], [$embedding], $providers, []];
        yield 'not a vector' => [[new FieldMapping('embedding', 'embedding', 'text'), $title], [$embedding], null, [
            Client::class . '::$embedding: #[EmbeddedVector] needs a "vector" field, not "text".',
        ]];
        yield 'unknown source' => [[$vector], [$embedding], null, [
            Client::class . '::$embedding: #[EmbeddedVector] reads "title", which is not a mapped field.',
        ]];
        yield 'source not a text' => [[$vector, new FieldMapping('title', 'title', 'integer')], [$embedding], null, [
            Client::class . '::$embedding: #[EmbeddedVector] reads "title", a "integer" field; a source must be a "string" or "text" field.',
        ]];
        yield 'state column taken' => [[$vector, $title, new FieldMapping('stale', 'embedding_stale', 'boolean')], [$embedding], null, [
            Client::class . '::$embedding: column "embedding_stale" is already used by $stale.',
        ]];
        yield 'unknown provider' => [[$vector, $title], [$embedding], new EmbeddingProviderRegistry(['other' => new HashingEmbeddingProvider(8)]), [
            Client::class . '::$embedding: #[EmbeddedVector] uses the embedding provider "default", which is not registered; registered providers: other.',
        ]];
        yield 'provider of another dimension' => [[$vector, $title], [$embedding], new EmbeddingProviderRegistry(['default' => new HashingEmbeddingProvider(16)]), [
            Client::class . '::$embedding: the embedding provider "default" produces 16 dimensions, but the column has 8; change #[Column(dimensions:)] and migrate, or use another provider.',
        ]];
    }

    /**
     * @param list<FieldMapping>     $fields
     * @param list<EmbeddingMapping> $embeddings
     * @param list<string>           $expected
     */
    #[DataProvider('embeddingCases')]
    public function testEmbeddedVectorRules(array $fields, array $embeddings, ?EmbeddingProviderRegistry $providers, array $expected): void
    {
        $metadata = new ClassMetadata(Client::class, 'client', new IdMapping('id'), [new FieldMapping('id', 'id', 'integer'), ...$fields], embeddings: $embeddings);
        $errors = new MetadataValidator(embeddings: $providers)->validate([$metadata]);

        self::assertSame($expected, array_map(static fn (MappingError $e): string => (string) $e, $errors));
    }

    public function testAllErrorsAreReportedAtOnce(): void
    {
        $metadata = [self::meta(Client::class, 'client', [new FieldMapping('total', 'total', 'decimal'), new FieldMapping('qty', 'qty', 'integer', length: 4), new FieldMapping('x', 'x', 'money')])];

        self::assertCount(3, self::errors($metadata));

        try {
            new MetadataValidator()->assertValid($metadata);
            self::fail('Expected MappingException.');
        } catch (MappingException $e) {
            self::assertStringStartsWith("The mapping is invalid (3 errors):\n- " . Client::class . '::$total: a decimal column', $e->getMessage());
            self::assertSame(3, substr_count($e->getMessage(), "\n- "));
        }
    }

    public function testSingleErrorMessage(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("The mapping is invalid (1 error):\n- " . Client::class . ': table "SHARED"');

        new MetadataValidator()->assertValid([self::meta(Invoice::class, 'shared'), self::meta(Client::class, 'SHARED')]);
    }

    /**
     * @param list<ClassMetadata<object>> $metadata
     *
     * @return list<string>
     */
    private static function warnings(array $metadata): array
    {
        return array_values(array_map(static fn (MappingError $e): string => (string) $e, array_filter(new MetadataValidator()->validate($metadata), static fn (MappingError $e): bool => MappingSeverity::Warning === $e->severity)));
    }

    // RG-04.6 : avertissements, qui ne bloquent pas
    public function testRedundantAndContradictoryRemovalPoliciesAreWarnings(): void
    {
        $lines = static fn (bool $orphanRemoval, bool $cascadeRemove): AssociationMapping => new AssociationMapping(Invoice::class, 'lines', AssociationType::OneToMany, InvoiceLine::class, 'invoice', cascade: $cascadeRemove ? [Cascade::Remove] : [], orphanRemoval: $orphanRemoval);
        $line = static fn (OnDelete $onDelete): ClassMetadata => self::meta(InvoiceLine::class, 'line', [], [self::toOne(InvoiceLine::class, 'invoice', Invoice::class, 'lines', onDelete: $onDelete)]);
        $warnings = self::warnings(...);

        $redundant = [self::meta(Invoice::class, 'invoice', [], [$lines(false, true)]), $line(OnDelete::Cascade)];
        self::assertSame([Invoice::class . '::$lines: cascade: remove repeats onDelete: CASCADE of ' . InvoiceLine::class . '::$invoice: the database already deletes these rows, without events. Keep one of them (RG-04.6).'], $warnings($redundant));
        new MetadataValidator()->assertValid($redundant);

        $contradictory = [self::meta(Invoice::class, 'invoice', [], [$lines(true, false)]), $line(OnDelete::SetNull)];
        self::assertSame([Invoice::class . '::$lines: orphanRemoval contradicts onDelete: SET NULL of ' . InvoiceLine::class . '::$invoice: the ORM deletes the rows the database would keep. Keep one of them (RG-04.6).'], $warnings($contradictory));

        self::assertSame([], $warnings([self::meta(Invoice::class, 'invoice', [], [$lines(false, true)]), $line(OnDelete::Restrict)]), 'cascade: remove alone is fine');
    }
}
