<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;

/**
 * Extraction d'un chemin JSON en texte et conversions (ADR 0010) : le chemin, validé, est écrit
 * en littéral ; NULL pour un chemin absent ou un null JSON sur les 4 bases.
 */
final class JsonSqlTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, string, string, string}>
     */
    public static function platforms(): iterable
    {
        $mysql = "CASE JSON_TYPE(JSON_EXTRACT(j, '$.\"lines\"[0].\"qty\"')) WHEN 'NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(j, '$.\"lines\"[0].\"qty\"')) END";

        yield 'MySQL' => [new MySqlPlatform('8.4'), $mysql, 'CAST(x AS SIGNED)', 'CAST(x AS DECIMAL(65, 30))'];
        yield 'MariaDB' => [new MariaDbPlatform('11.8'), $mysql, 'CAST(x AS SIGNED)', 'CAST(x AS DECIMAL(65, 30))'];
        yield 'PostgreSQL' => [new PostgreSqlPlatform('16'), "jsonb_extract_path_text(j, 'lines', '0', 'qty')", 'CAST(x AS BIGINT)', 'CAST(x AS NUMERIC)'];
        yield 'SQLite' => [new SqlitePlatform('3.46'), "CAST(json_extract(j, '$.\"lines\"[0].\"qty\"') AS TEXT)", 'CAST(x AS INTEGER)', 'CAST(x AS NUMERIC)'];
    }

    #[DataProvider('platforms')]
    public function testExtractAndCast(AbstractPlatform $platform, string $extract, string $integer, string $decimal): void
    {
        self::assertSame($extract, $platform->getJsonExtractTextSql('j', ['lines', 0, 'qty']));
        self::assertSame($integer, $platform->getCastSql('x', 'integer'));
        self::assertSame($decimal, $platform->getCastSql('x', 'decimal'));
    }

    /**
     * @return iterable<string, array{list<string|int>, string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'empty' => [[], 'Invalid JSON path: it is empty.'];
        yield 'quote' => [["a'b"], "Invalid JSON path: 'a\\'b' is neither a key of letters, digits and underscores nor an array index."];
        yield 'dot' => [['a.b'], "Invalid JSON path: 'a.b' is neither a key of letters, digits and underscores nor an array index."];
        yield 'negative index' => [['a', -1], 'Invalid JSON path: -1 is neither a key of letters, digits and underscores nor an array index.'];
    }

    /**
     * @param list<string|int> $path
     */
    #[DataProvider('invalidPaths')]
    public function testInvalidPathIsNeverWritten(array $path, string $message): void
    {
        foreach ([new MySqlPlatform('8.4'), new PostgreSqlPlatform('16'), new SqlitePlatform('3.46')] as $platform) {
            try {
                $platform->getJsonExtractTextSql('j', $path);
                self::fail('Expected InvalidParameterException on ' . $platform->getName());
            } catch (InvalidParameterException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}
