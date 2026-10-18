<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Type;

use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
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

    // SQLite perdrait silencieusement les chiffres au-delà de 15 : Prism refuse (RG-01.5)
    public function testSqliteRefusesMoreThanFifteenSignificantDigits(): void
    {
        $sqlite = new ConversionContext(new SqlitePlatform('3.46'));
        $type = new DecimalType();

        self::assertSame('9999999999999.99', $type->toDatabase('9999999999999.99', $sqlite));
        // zéros de tête et de queue non significatifs
        self::assertSame('-000123456789012345.000', $type->toDatabase('-000123456789012345.000', $sqlite));
        self::assertSame('100000000000000000000', $type->toDatabase('100000000000000000000', $sqlite));

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Cannot store a decimal with 16 significant digits on SQLite: the platform keeps at most 15 exactly');
        $type->toDatabase('99999999999999.99', $sqlite);
    }

    public function testServerPlatformsKeepEveryDigit(): void
    {
        foreach ([new MySqlPlatform('8.4'), new MariaDbPlatform('11.8'), new PostgreSqlPlatform('16')] as $platform) {
            self::assertNull($platform->getDecimalMaxSignificantDigits());
            self::assertSame('123456789012345678.90', new DecimalType()->toDatabase('123456789012345678.90', new ConversionContext($platform)));
        }
    }

    public function testBindsAsString(): void
    {
        self::assertSame(ParameterType::String, new DecimalType()->getBindingType());
    }
}
