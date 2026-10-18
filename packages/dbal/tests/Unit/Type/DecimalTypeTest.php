<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Type\DecimalType;

// RG-01.5 : decimal est toujours une string, jamais un float
final class DecimalTypeTest extends TypeTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function amounts(): iterable
    {
        yield 'two decimals' => ['12.50'];
        yield 'negative' => ['-0.05'];
        yield 'explicit plus' => ['+3'];
        yield 'beyond float precision' => ['12345678901234567.89'];
    }

    #[DataProvider('amounts')]
    public function testNumericStringsAreKeptVerbatim(string $amount): void
    {
        self::assertSame($amount, new DecimalType()->toDatabase($amount, self::context()));
        self::assertSame($amount, new DecimalType()->toPhp($amount, self::context()));
    }

    public function testIntIsAccepted(): void
    {
        self::assertSame('100', new DecimalType()->toDatabase(100, self::context()));
        self::assertSame('100', new DecimalType()->toPhp(100, self::context()));
    }

    public function testFloatIsRefusedWithAHint(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('pass the amount as a numeric string (for example "12.50")');

        new DecimalType()->toDatabase(12.5, self::context());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'comma' => ['12,50'];
        yield 'exponent' => ['1e3'];
        yield 'no leading digit' => ['.5'];
        yield 'text' => ['twelve'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformed')]
    public function testMalformedStringsAreRefused(string $value): void
    {
        $this->expectException(ConversionException::class);

        new DecimalType()->toDatabase($value, self::context());
    }

    public function testFloatFromAMisdeclaredColumnIsStillReturnedAsString(): void
    {
        self::assertSame('12.5', new DecimalType()->toPhp(12.5, self::context()));
    }

    public function testBindsAsString(): void
    {
        self::assertSame(ParameterType::String, new DecimalType()->getBindingType());
    }
}
