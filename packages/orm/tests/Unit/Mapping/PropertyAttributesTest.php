<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\Column;
use Prism\Orm\Mapping\Embedded;
use Prism\Orm\Mapping\EmbeddedVector;
use Prism\Orm\Mapping\GeneratedValue;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Mapping\VectorIndex;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\FixtureStatus;
use Prism\Orm\Vector\Distance;

final class PropertyAttributesTest extends TestCase
{
    public function testColumnDefaultsLeaveInferenceToTheDriver(): void
    {
        $column = new Column();

        self::assertNull($column->type);
        self::assertNull($column->nullable);
        self::assertNull($column->length);
        self::assertFalse($column->sensitive);
        self::assertFalse($column->lazy);
        self::assertTrue($column->insertable);
        self::assertTrue($column->updatable);
    }

    public function testDecimalColumn(): void
    {
        $column = new Column(type: 'decimal', precision: 12, scale: 2);

        self::assertSame(12, $column->precision);
        self::assertSame(2, $column->scale);
    }

    public function testScaleCannotExceedPrecision(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('scale (4) cannot exceed precision (2)');

        new Column(type: 'decimal', precision: 2, scale: 4);
    }

    public function testLengthMustBePositive(): void
    {
        $this->expectException(MappingException::class);

        new Column(length: 0);
    }

    public function testDimensionsAreBounded(): void
    {
        $this->expectException(MappingException::class);

        new Column(type: 'vector', dimensions: 20000);
    }

    public function testEnumTypeMustBeBackedEnum(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('must be a backed enum');

        // instanciation par Reflection, comme pour un attribut lu sur une entité : le PHPDoc n'y est pas vérifié
        new \ReflectionClass(Column::class)->newInstanceArgs(['enumType' => \stdClass::class]);
    }

    public function testEnumTypeAcceptsBackedEnum(): void
    {
        self::assertSame(FixtureStatus::class, new Column(enumType: FixtureStatus::class)->enumType);
    }

    public function testReadOnlyColumnCannotHaveDefault(): void
    {
        $this->expectException(MappingException::class);

        new Column(default: 0, insertable: false, updatable: false);
    }

    public function testGeneratedValueDefaultsToAuto(): void
    {
        self::assertSame(GenerationStrategy::Auto, new GeneratedValue()->strategy);
    }

    public function testSequenceNameRequiresSequenceStrategy(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('only allowed with');

        new GeneratedValue(GenerationStrategy::UuidV7, sequenceName: 'invoice_seq');
    }

    public function testEmbeddedPrefixVariants(): void
    {
        self::assertNull(new Embedded()->columnPrefix);
        self::assertFalse(new Embedded(columnPrefix: false)->columnPrefix);
        self::assertSame('billing_', new Embedded(columnPrefix: 'billing_')->columnPrefix);
    }

    public function testVectorIndexDefaults(): void
    {
        $index = new VectorIndex();

        self::assertSame(Distance::Cosine, $index->distance);
        self::assertSame(16, $index->m);
        self::assertSame(64, $index->efConstruction);
    }

    public function testVectorIndexEfConstructionMustBeAtLeastTwiceM(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('at least 2 × m (64)');

        new VectorIndex(m: 32, efConstruction: 40);
    }

    public function testEmbeddedVectorNormalisesSingleSource(): void
    {
        self::assertSame(['content'], new EmbeddedVector('content')->source);
        self::assertSame(['title', 'content'], new EmbeddedVector(['title', 'content'])->source);
    }

    public function testEmbeddedVectorRejectsEmptyModel(): void
    {
        $this->expectException(MappingException::class);

        new EmbeddedVector('content', model: '');
    }
}
