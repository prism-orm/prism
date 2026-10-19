<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Inferred;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Person;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Priority;

// briques de l'hydrateur de la phase 3 : entité ↔ colonnes, sans base
final class RowMappingTest extends TestCase
{
    public function testEmbeddablesRoundTrip(): void
    {
        $metadata = new AttributeDriver()->loadMetadata(Person::class);
        $person = new Person(new Address('1 rue de la Paix', '75002', 'Paris'));
        $person->uuid = '0192f1e4-7b5c-7d3a-9f00-1a2b3c4d5e6f';

        $row = $metadata->extractRow($person);
        self::assertSame(['uuid' => '0192f1e4-7b5c-7d3a-9f00-1a2b3c4d5e6f', 'home_street' => '1 rue de la Paix', 'home_zip' => '75002', 'home_city' => 'Paris', 'street' => null, 'zip' => null, 'city' => null], $row);

        // RG-03.12 : sans constructeur ; RG-02.6 : propriétés readonly écrites une fois
        $copy = new \ReflectionClass(Person::class)->newInstanceWithoutConstructor();
        $metadata->hydrateRow($copy, $row);

        self::assertSame('Paris', $copy->home->city);
        self::assertNull($copy->work, 'RG-02.5 : all columns NULL gives a null embeddable');
        self::assertEquals($person, $copy);
    }

    public function testFilledNullableEmbeddableIsBuilt(): void
    {
        $metadata = new AttributeDriver()->loadMetadata(Person::class);
        $person = new \ReflectionClass(Person::class)->newInstanceWithoutConstructor();

        $metadata->hydrateRow($person, ['uuid' => 'x', 'home_street' => 'h', 'home_zip' => 'h', 'home_city' => 'h', 'street' => 'a', 'zip' => 'b', 'city' => 'c']);

        self::assertInstanceOf(Address::class, $person->work);
        self::assertSame(['a', 'b', 'c'], [$person->work->street, $person->work->zip, $person->work->city]);
    }

    public function testEnumsAndPartialRows(): void
    {
        $metadata = new AttributeDriver()->loadMetadata(Inferred::class);
        $entity = new Inferred();
        $entity->status = FixtureStatus::Paid;
        $entity->priority = Priority::High;

        $row = $metadata->extractRow($entity);
        self::assertSame(['paid', 2], [$row['status'], $row['priority']]);

        $copy = new \ReflectionClass(Inferred::class)->newInstanceWithoutConstructor();
        $metadata->hydrateRow($copy, ['status' => 'draft', 'priority' => 2, 'code' => 'ABC']);
        self::assertSame([FixtureStatus::Draft, Priority::High, 'ABC'], [$copy->status, $copy->priority, $copy->code]);
        // colonne absente de la ligne : propriété laissée telle quelle
        self::assertFalse(new \ReflectionProperty(Inferred::class, 'createdAt')->isInitialized($copy));
        // propriété non initialisée : lue comme null
        self::assertNull($metadata->extractRow($copy)['created_at']);
    }

    public function testUnknownEnumValueIsReported(): void
    {
        $metadata = new AttributeDriver()->loadMetadata(Inferred::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Inferred::class . "::\$status: 'archived' is not a case of enum " . FixtureStatus::class . '.');

        $metadata->hydrateRow(new \ReflectionClass(Inferred::class)->newInstanceWithoutConstructor(), ['status' => 'archived']);
    }

    public function testColumnTypes(): void
    {
        $types = new AttributeDriver()->loadMetadata(Person::class)->getColumnTypes();

        self::assertSame(['uuid' => 'string', 'home_street' => 'string', 'home_zip' => 'string', 'home_city' => 'string', 'street' => 'string', 'zip' => 'string', 'city' => 'string'], $types);
    }

    // décision C : la Reflection n'est pas sérialisée, elle est reconstruite au premier usage
    public function testReflectionIsRebuiltAfterUnserialize(): void
    {
        $metadata = new AttributeDriver()->loadMetadata(Person::class);
        $metadata->extractRow(new Person(new Address('a', 'b', 'c')));
        self::assertGreaterThan(0, $metadata->getReflectionCount());

        $restored = unserialize(serialize($metadata));
        self::assertInstanceOf(\Prism\Orm\Metadata\ClassMetadata::class, $restored);
        self::assertSame(0, $restored->getReflectionCount());
        self::assertSame('c', $restored->extractRow(new Person(new Address('a', 'b', 'c')))['home_city']);
    }
}
