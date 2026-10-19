<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Type\BigIntType;
use Prism\Dbal\Type\BinaryType;
use Prism\Dbal\Type\BooleanType;
use Prism\Dbal\Type\FloatType;
use Prism\Dbal\Type\IntegerType;
use Prism\Dbal\Type\JsonType;
use Prism\Dbal\Type\SmallIntType;
use Prism\Dbal\Type\StringType;
use Prism\Dbal\Type\TextType;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Dbal\Type\UuidType;

final class ScalarTypesTest extends TypeTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function allTypes(): iterable
    {
        foreach (TypeRegistry::withDefaults()->getNames() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('allTypes')]
    public function testNullStaysNullBothWays(string $name): void
    {
        $type = TypeRegistry::withDefaults()->get($name);

        self::assertNull($type->toDatabase(null, self::context()));
        self::assertNull($type->toPhp(null, self::context()));
    }

    public function testStrings(): void
    {
        foreach ([new StringType(), new TextType()] as $type) {
            self::assertSame('Société', $type->toDatabase('Société', self::context()));
            self::assertSame('42', $type->toPhp(42, self::context()));
            self::assertSame(ParameterType::String, $type->getBindingType());
        }
    }

    /**
     * @return iterable<string, array{Type}>
     */
    public static function integerTypes(): iterable
    {
        yield 'integer' => [new IntegerType()];
        yield 'smallint' => [new SmallIntType()];
        yield 'bigint' => [new BigIntType()];
    }

    #[DataProvider('integerTypes')]
    public function testIntegers(Type $type): void
    {
        self::assertSame(42, $type->toDatabase(42, self::context()));
        self::assertSame(-7, $type->toPhp('-7', self::context()));
        self::assertSame(ParameterType::Integer, $type->getBindingType());
    }

    public function testBigIntCoversTheFullSignedRange(): void
    {
        $type = new BigIntType();

        self::assertSame(\PHP_INT_MAX, $type->toPhp((string) \PHP_INT_MAX, self::context()));
        self::assertSame(\PHP_INT_MIN, $type->toPhp((string) \PHP_INT_MIN, self::context()));

        $this->expectException(ConversionException::class);
        $type->toPhp('9223372036854775808', self::context());
    }

    /**
     * @return iterable<string, array{Type, mixed}>
     */
    public static function invalidPhpValues(): iterable
    {
        yield 'string from int' => [new StringType(), 42];
        yield 'integer from numeric string' => [new IntegerType(), '42'];
        yield 'integer from float' => [new IntegerType(), 4.2];
        yield 'boolean from int' => [new BooleanType(), 1];
        yield 'float from string' => [new FloatType(), '1.5'];
        yield 'float INF' => [new FloatType(), \INF];
        yield 'float NAN' => [new FloatType(), \NAN];
        yield 'uuid malformed' => [new UuidType(), 'not-a-uuid'];
        yield 'binary from int' => [new BinaryType(), 1];
        yield 'json resource' => [new JsonType(), \STDIN];
    }

    #[DataProvider('invalidPhpValues')]
    public function testInvalidPhpValuesAreRefused(Type $type, mixed $value): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage(\sprintf('database type "%s"', $type->getName()));

        $type->toDatabase($value, self::context());
    }

    /**
     * @return iterable<string, array{Type, mixed}>
     */
    public static function invalidDatabaseValues(): iterable
    {
        yield 'integer from text' => [new IntegerType(), 'abc'];
        yield 'boolean from 2' => [new BooleanType(), 2];
        yield 'float from text' => [new FloatType(), 'abc'];
        yield 'json malformed' => [new JsonType(), '{"a":'];
        yield 'json from int' => [new JsonType(), 1];
        yield 'uuid malformed' => [new UuidType(), 'zzz'];
        yield 'string from array' => [new StringType(), []];
        yield 'binary from int' => [new BinaryType(), 1];
    }

    #[DataProvider('invalidDatabaseValues')]
    public function testInvalidDatabaseValuesAreRefused(Type $type, mixed $value): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage(\sprintf('PHP value of type "%s"', $type->getName()));

        $type->toPhp($value, self::context());
    }

    public function testConversionMessagesNeverContainTheValue(): void
    {
        try {
            new UuidType()->toDatabase('IBAN FR76 3000 6000 0112 3456 7890 189', self::context());
            self::fail('Expected ConversionException.');
        } catch (ConversionException $e) {
            self::assertStringNotContainsString('FR76', $e->getMessage());
        }
    }

    public function testBooleanReadsEveryPlatformRepresentation(): void
    {
        $type = new BooleanType();

        foreach ([true, 1, '1', 't', 'true'] as $value) {
            self::assertTrue($type->toPhp($value, self::context()));
        }
        foreach ([false, 0, '0', 'f', 'false'] as $value) {
            self::assertFalse($type->toPhp($value, self::context()));
        }
        self::assertSame(ParameterType::Boolean, $type->getBindingType());
    }

    // un cast (string) passerait par l'ini precision=14 et perdrait des chiffres
    public function testFloatKeepsFullPrecision(): void
    {
        $type = new FloatType();

        self::assertSame('0.30000000000000004', $type->toDatabase(0.1 + 0.2, self::context()));
        self::assertSame('1.0', $type->toDatabase(1, self::context()));
        self::assertSame(0.1, $type->toPhp('0.1', self::context()));
        self::assertSame(3.0, $type->toPhp(3, self::context()));
    }

    public function testJson(): void
    {
        $type = new JsonType();
        $value = ['client' => 'Société', 'url' => 'https://a.b/c', 'ratio' => 1.0, 'tags' => ['x']];

        self::assertSame('{"client":"Société","url":"https://a.b/c","ratio":1.0,"tags":["x"]}', $type->toDatabase($value, self::context()));
        self::assertSame($value, $type->toPhp($type->toDatabase($value, self::context()), self::context()));
        self::assertSame('false', $type->toDatabase(false, self::context()));
    }

    public function testUuidIsCanonicalLowercase(): void
    {
        $type = new UuidType();

        self::assertSame('0192f1e4-7b5c-7d3a-9f00-1a2b3c4d5e6f', $type->toDatabase('0192F1E4-7B5C-7D3A-9F00-1A2B3C4D5E6F', self::context()));
        self::assertSame('0192f1e4-7b5c-7d3a-9f00-1a2b3c4d5e6f', $type->toPhp('0192F1E4-7B5C-7D3A-9F00-1A2B3C4D5E6F', self::context()));
    }

    public function testBinaryReadsStreams(): void
    {
        $type = new BinaryType();
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\x00\xFFbytes");
        rewind($stream);

        self::assertSame("\x00\xFFbytes", $type->toPhp($stream, self::context()));
        self::assertSame("\x00\xFF", $type->toDatabase("\x00\xFF", self::context()));
        self::assertSame(ParameterType::Binary, $type->getBindingType());
    }
}
