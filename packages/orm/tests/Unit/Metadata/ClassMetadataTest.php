<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Mapping\LifecycleEvent;
use Prism\Orm\Mapping\OnDelete;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\EmbeddedMapping;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\IdMapping;
use Prism\Orm\Metadata\JoinTableMapping;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Client;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Tag;

final class ClassMetadataTest extends TestCase
{
    /**
     * @return ClassMetadata<Invoice>
     */
    private static function invoice(): ClassMetadata
    {
        return new ClassMetadata(
            Invoice::class,
            'invoice',
            new IdMapping('id', GenerationStrategy::Auto),
            [new FieldMapping('id', 'id', 'bigint'), new FieldMapping('total', 'total', 'decimal', precision: 12, scale: 2), new FieldMapping('version', 'version', 'integer')],
            [new EmbeddedMapping('billingAddress', Address::class, 'billing_', true, [new FieldMapping('city', 'billing_city', 'string', nullable: true)])],
            [new AssociationMapping(Invoice::class, 'client', AssociationType::ManyToOne, Client::class, inversedBy: 'invoices', joinColumn: 'client_id', nullable: false)],
            versionField: 'version',
            lifecycleCallbacks: ['prePersist' => ['onPrePersist']],
        );
    }

    public function testAccessors(): void
    {
        $metadata = self::invoice();

        self::assertSame('id', $metadata->getIdField()->columnName);
        self::assertSame(['id', 'total', 'version'], array_map(static fn (FieldMapping $f): string => $f->fieldName, $metadata->getFields()));
        self::assertSame('billing_city', $metadata->getField('billingAddress.city')->columnName);
        self::assertTrue($metadata->hasField('billingAddress.city'));
        self::assertFalse($metadata->hasField('billingAddress.zip'));
        self::assertFalse($metadata->hasField('client'));
        self::assertTrue($metadata->hasAssociation('client'));
        self::assertTrue($metadata->getAssociation('client')->isOwningSide());
        self::assertSame(['onPrePersist'], $metadata->getLifecycleCallbacks(LifecycleEvent::PrePersist));
        self::assertSame([], $metadata->getLifecycleCallbacks(LifecycleEvent::PostLoad));
        self::assertTrue($metadata->id->isGenerated());
    }

    public function testColumnsIncludeEmbeddedFieldsAndOwnedJoinColumns(): void
    {
        self::assertSame(['id', 'total', 'version', 'billing_city', 'client_id'], self::invoice()->getColumnNames());
    }

    public function testFieldPathByColumn(): void
    {
        $metadata = self::invoice();

        self::assertSame('total', $metadata->getFieldPathByColumn('TOTAL'));
        self::assertSame('billingAddress.city', $metadata->getFieldPathByColumn('billing_city'));
        self::assertNull($metadata->getFieldPathByColumn('client_id'));
    }

    public function testUnknownField(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Invoice::class . ' has no mapped field "billingAddress.zip".');

        self::invoice()->getField('billingAddress.zip');
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidMetadata(): iterable
    {
        $id = new FieldMapping('id', 'id', 'bigint');
        $address = new EmbeddedMapping('billingAddress', Address::class, 'billing_', true, [new FieldMapping('city', 'billing_city', 'string')]);
        $client = static fn (string $field): AssociationMapping => new AssociationMapping(Invoice::class, $field, AssociationType::ManyToOne, Client::class, joinColumn: 'client_id');

        yield 'identifier not mapped' => [static fn (): ClassMetadata => new ClassMetadata(Invoice::class, 'invoice', new IdMapping('uuid'), [$id]), 'the identifier must be a mapped scalar field'];
        yield 'version not mapped' => [static fn (): ClassMetadata => new ClassMetadata(Invoice::class, 'invoice', new IdMapping('id'), [$id], versionField: 'version'), 'the version field must be a mapped scalar field'];
        yield 'field mapped twice' => [static fn (): ClassMetadata => new ClassMetadata(Invoice::class, 'invoice', new IdMapping('id'), [$id, $id]), 'Invoice::$id: the property is mapped twice'];
        // la relation n'est pas la première clé : couvre l'union des listes de noms
        // deux embeddables : avec « + », les relations d'index 0 et 1 seraient ignorées
        $shipping = new EmbeddedMapping('shippingAddress', Address::class, 'shipping_', true, [new FieldMapping('city', 'shipping_city', 'string')]);
        yield 'association named like a field' => [static fn (): ClassMetadata => new ClassMetadata(Invoice::class, 'invoice', new IdMapping('id'), [$id, new FieldMapping('client', 'client', 'string')], [$address, $shipping], [$client('other'), $client('client')]), 'Invoice::$client: the property is mapped twice'];
        yield 'embedded named like an association' => [static fn (): ClassMetadata => new ClassMetadata(Invoice::class, 'invoice', new IdMapping('id'), [$id], [$address], [$client('billingAddress')]), 'Invoice::$billingAddress: the property is mapped twice'];
        yield 'empty embeddable' => [static fn (): EmbeddedMapping => new EmbeddedMapping('a', Address::class, 'a_', false, []), 'an embeddable must map at least one field'];
        yield 'empty column name' => [static fn (): FieldMapping => new FieldMapping('total', ' ', 'decimal'), 'Invalid mapping for field "total": columnName must not be empty'];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[DataProvider('invalidMetadata')]
    public function testInvalidMetadataIsRefused(\Closure $build, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    /**
     * @return iterable<string, array{\Closure(): AssociationMapping, string}>
     */
    public static function invalidAssociations(): iterable
    {
        $make = static fn (AssociationType $type, ?string $mappedBy = null, ?string $inversedBy = null, ?string $joinColumn = null, ?JoinTableMapping $joinTable = null): AssociationMapping => new AssociationMapping(Invoice::class, 'rel', $type, Tag::class, $mappedBy, $inversedBy, $joinColumn, joinTable: $joinTable);

        yield 'mappedBy and inversedBy' => [static fn (): AssociationMapping => $make(AssociationType::ManyToMany, 'a', 'b'), 'mappedBy and inversedBy cannot be combined'];
        yield 'OneToMany without mappedBy' => [static fn (): AssociationMapping => $make(AssociationType::OneToMany), 'a OneToMany is always the inverse side'];
        yield 'ManyToOne with mappedBy' => [static fn (): AssociationMapping => $make(AssociationType::ManyToOne, 'invoice'), 'a ManyToOne is always the owning side'];
        yield 'owning to-one without join column' => [static fn (): AssociationMapping => $make(AssociationType::OneToOne), 'needs a join column'];
        yield 'owning ManyToMany without join table' => [static fn (): AssociationMapping => $make(AssociationType::ManyToMany), 'needs a join table'];
        yield 'inverse side with join column' => [static fn (): AssociationMapping => $make(AssociationType::OneToOne, 'owner', joinColumn: 'x_id'), 'the inverse side (mappedBy) cannot declare a join column'];
    }

    /**
     * @param \Closure(): AssociationMapping $build
     */
    #[DataProvider('invalidAssociations')]
    public function testInvalidAssociationsAreRefused(\Closure $build, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    public function testOwningAndInverseSides(): void
    {
        $owning = new AssociationMapping(Invoice::class, 'tags', AssociationType::ManyToMany, Tag::class, inversedBy: 'invoices', joinTable: new JoinTableMapping('invoice_tag', 'invoice_id', 'tag_id', OnDelete::Cascade));
        $inverse = new AssociationMapping(Tag::class, 'invoices', AssociationType::ManyToMany, Invoice::class, mappedBy: 'tags');
        $oneToMany = new AssociationMapping(Client::class, 'invoices', AssociationType::OneToMany, Invoice::class, mappedBy: 'client');

        self::assertTrue($owning->isOwningSide());
        self::assertFalse($inverse->isOwningSide());
        self::assertFalse($oneToMany->isOwningSide());
        self::assertTrue($oneToMany->isCollection());
        self::assertFalse($oneToMany->isToOne());
        self::assertTrue(AssociationType::OneToOne->isToOne());
    }

    public function testFieldWithers(): void
    {
        $field = new FieldMapping('city', 'city', 'string');

        self::assertSame('billing_city', $field->withColumnName('billing_city')->columnName);
        self::assertTrue($field->withNullable(true)->nullable);
        self::assertFalse($field->nullable);
        self::assertSame('city', $field->columnName);
    }
}
