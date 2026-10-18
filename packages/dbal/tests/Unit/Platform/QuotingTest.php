<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidIdentifierException;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;

final class QuotingTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function mysqlFamily(): iterable
    {
        yield 'MySQL' => [new MySqlPlatform('8.4')];
        yield 'MariaDB' => [new MariaDbPlatform('11.8')];
    }

    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function standardFamily(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSqlPlatform('16')];
        yield 'SQLite' => [new SqlitePlatform('3.46')];
    }

    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function allPlatforms(): iterable
    {
        yield from self::mysqlFamily();
        yield from self::standardFamily();
    }

    // RG-01.3 : tout identifiant est cité, mot réservé ou non
    #[DataProvider('mysqlFamily')]
    public function testMysqlQuotesWithBackticks(AbstractPlatform $platform): void
    {
        self::assertSame('`invoice`', $platform->quoteIdentifier('invoice'));
        self::assertSame('`order`', $platform->quoteIdentifier('order'));
        self::assertSame('`we``ird"name`', $platform->quoteIdentifier('we`ird"name'));
        self::assertSame('`schema.table`', $platform->quoteIdentifier('schema.table'));
    }

    // RG-01.3
    #[DataProvider('standardFamily')]
    public function testStandardPlatformsQuoteWithDoubleQuotes(AbstractPlatform $platform): void
    {
        self::assertSame('"invoice"', $platform->quoteIdentifier('invoice'));
        self::assertSame('"user"', $platform->quoteIdentifier('user'));
        self::assertSame('"we`ird""name"', $platform->quoteIdentifier('we`ird"name'));
        self::assertSame('"schema.table"', $platform->quoteIdentifier('schema.table'));
    }

    #[DataProvider('allPlatforms')]
    public function testCaseIsPreserved(AbstractPlatform $platform): void
    {
        self::assertStringContainsString('InvoiceLine', $platform->quoteIdentifier('InvoiceLine'));
    }

    /**
     * @return iterable<string, array{AbstractPlatform, string, string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        foreach (self::allPlatforms() as $name => [$platform]) {
            yield $name . ' empty' => [$platform, '', 'the name is empty'];
            yield $name . ' NUL byte' => [$platform, "in\0voice", 'valid UTF-8 without NUL bytes'];
            yield $name . ' invalid UTF-8' => [$platform, "invo\xC3ice", 'valid UTF-8 without NUL bytes'];
        }
    }

    #[DataProvider('invalidIdentifiers')]
    public function testInvalidIdentifiersAreRejected(AbstractPlatform $platform, string $name, string $message): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage($message);

        $platform->quoteIdentifier($name);
    }

    // PostgreSQL tronquerait silencieusement au-delà de 63 octets
    public function testPostgreSqlLimitIsCountedInBytes(): void
    {
        $platform = new PostgreSqlPlatform('16');

        self::assertSame('"' . str_repeat('a', 63) . '"', $platform->quoteIdentifier(str_repeat('a', 63)));

        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('64 bytes, the maximum is 63');
        // 32 « é » = 64 octets
        $platform->quoteIdentifier(str_repeat('é', 32));
    }

    #[DataProvider('mysqlFamily')]
    public function testMysqlLimitIsCountedInCharacters(AbstractPlatform $platform): void
    {
        self::assertSame(64, $platform->getMaxIdentifierLength());
        // 64 caractères multioctets restent valides sous MySQL
        $platform->quoteIdentifier(str_repeat('é', 64));

        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('65 characters, the maximum is 64');
        $platform->quoteIdentifier(str_repeat('é', 65));
    }

    public function testSqliteHasNoLengthLimit(): void
    {
        $platform = new SqlitePlatform('3.46');

        self::assertNull($platform->getMaxIdentifierLength());
        self::assertSame(302, \strlen($platform->quoteIdentifier(str_repeat('a', 300))));
    }

    // RG-01.4 : littéraux réservés au DDL
    #[DataProvider('standardFamily')]
    public function testStandardStringLiterals(AbstractPlatform $platform): void
    {
        self::assertSame("'draft'", $platform->quoteStringLiteral('draft'));
        self::assertSame("''", $platform->quoteStringLiteral(''));
        self::assertSame("'O''Reilly'", $platform->quoteStringLiteral("O'Reilly"));
        self::assertSame("'C:\\temp'", $platform->quoteStringLiteral('C:\temp'));
        self::assertSame("'''; DROP TABLE x; --'", $platform->quoteStringLiteral("'; DROP TABLE x; --"));
    }

    #[DataProvider('mysqlFamily')]
    public function testMysqlStringLiteralsAlsoEscapeBackslashes(AbstractPlatform $platform): void
    {
        self::assertSame("'O''Reilly'", $platform->quoteStringLiteral("O'Reilly"));
        self::assertSame("'C:\\\\temp'", $platform->quoteStringLiteral('C:\temp'));
        // sans doublement du backslash, \' fermerait la chaîne
        self::assertSame("'\\\\'''", $platform->quoteStringLiteral("\\'"));
    }

    #[DataProvider('allPlatforms')]
    public function testNulByteIsRefusedInLiterals(AbstractPlatform $platform): void
    {
        $this->expectException(InvalidParameterException::class);
        $this->expectExceptionMessage('NUL byte');

        $platform->quoteStringLiteral("a\0b");
    }

    public function testPostgreSqlForcesStandardConformingStrings(): void
    {
        self::assertSame(['SET standard_conforming_strings = on'], new PostgreSqlPlatform('16')->getSessionInitSql());
    }
}
