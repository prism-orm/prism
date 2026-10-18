<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\Auditable;
use Prism\Orm\Mapping\Blameable;
use Prism\Orm\Mapping\Entity;
use Prism\Orm\Mapping\EntityListeners;
use Prism\Orm\Mapping\FullTextIndex;
use Prism\Orm\Mapping\Index;
use Prism\Orm\Mapping\SoftDeletable;
use Prism\Orm\Mapping\Table;
use Prism\Orm\Mapping\Tenanted;
use Prism\Orm\Mapping\Timestampable;
use Prism\Orm\Mapping\TrackingPolicy;
use Prism\Orm\Mapping\UniqueConstraint;

final class ClassAttributesTest extends TestCase
{
    public function testEntityDefaults(): void
    {
        $entity = new Entity();

        self::assertNull($entity->repositoryClass);
        self::assertSame(TrackingPolicy::Implicit, $entity->trackingPolicy);
        self::assertFalse($entity->readOnly);
    }

    public function testTableAcceptsKnownOptions(): void
    {
        $table = new Table('invoice', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci']);

        self::assertSame('invoice', $table->name);
        self::assertSame('InnoDB', $table->options['engine']);
    }

    public function testTableRejectsUnknownOption(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('unknown option "engin"');

        new Table(options: ['engin' => 'InnoDB']);
    }

    public function testTableRejectsEmptyName(): void
    {
        $this->expectException(MappingException::class);

        new Table('  ');
    }

    public function testIndexRequiresFields(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('non-empty list');

        new Index([]);
    }

    public function testIndexRejectsDuplicateFields(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('duplicate');

        new Index(['issuedAt', 'issuedAt']);
    }

    public function testIndexAcceptsEmbeddedFieldPath(): void
    {
        self::assertSame(['billingAddress.zip'], new Index(['billingAddress.zip'])->fields);
    }

    public function testUniqueConstraintRejectsInvalidFieldName(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('"invoice-number" is not a valid field name');

        new UniqueConstraint(['invoice-number']);
    }

    public function testEntityListenersRequiresAtLeastOneClass(): void
    {
        $this->expectException(MappingException::class);

        new EntityListeners([]);
    }

    public function testTenantedDefaults(): void
    {
        $tenanted = new Tenanted();

        self::assertFalse($tenanted->global);
        self::assertNull($tenanted->column);
        self::assertFalse($tenanted->strictForeignKeys);
    }

    public function testAuditableAcceptsIsoDuration(): void
    {
        $auditable = new Auditable(exclude: ['updatedAt'], retention: 'P10Y');

        self::assertSame(['updatedAt'], $auditable->exclude);
        self::assertSame('P10Y', $auditable->retention);
    }

    public function testAuditableRejectsInvalidRetention(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('not a valid ISO 8601 duration');

        new Auditable(retention: '10 years');
    }

    public function testTimestampableDefaultsAndDisabledUpdate(): void
    {
        self::assertSame('updatedAt', new Timestampable()->updatedAt);
        self::assertNull(new Timestampable(updatedAt: null)->updatedAt);
    }

    public function testBlameableDefaults(): void
    {
        $blameable = new Blameable();

        self::assertSame('createdBy', $blameable->createdBy);
        self::assertSame('updatedBy', $blameable->updatedBy);
    }

    public function testSoftDeletableDefaultField(): void
    {
        self::assertSame('deletedAt', new SoftDeletable()->field);
    }

    public function testFullTextIndex(): void
    {
        $index = new FullTextIndex(['title', 'content'], language: 'french');

        self::assertSame(['title', 'content'], $index->fields);
        self::assertSame('french', $index->language);
    }
}
