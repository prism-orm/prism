<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Tests\Support\TestDatabase;

final class QuotingTest extends TestCase
{
    private const string TABLE = 'order';

    private const array COLUMNS = ['group', 'user', 'select', 'quo"te`d', 'Mixed Case'];

    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }

        $this->connection = TestDatabase::connection();
        $this->dropTable();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropTable();
            $this->connection->close();
        }
    }

    // RG-01.3 : un mot réservé SQL comme nom de table ou de colonne fonctionne
    public function testReservedWordsAndSpecialCharactersAsIdentifiers(): void
    {
        $columns = array_map($this->quote(...), self::COLUMNS);

        $this->connection->executeStatement(\sprintf(
            'CREATE TABLE %s (%s)',
            $this->quote(self::TABLE),
            implode(', ', array_map(static fn (string $c): string => $c . ' VARCHAR(20) NULL', $columns)),
        ));

        $this->connection->executeStatement(
            \sprintf('INSERT INTO %s (%s) VALUES (?, ?, ?, ?, ?)', $this->quote(self::TABLE), implode(', ', $columns)),
            ['g', 'u', 's', 'q', 'm'],
        );

        $row = $this->connection->executeQuery(\sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            implode(', ', $columns),
            $this->quote(self::TABLE),
            $this->quote('group'),
        ), ['g'])->fetchAssociative();

        // les noms reviennent exactement comme déclarés, casse et caractères spéciaux compris
        self::assertSame(array_combine(self::COLUMNS, ['g', 'u', 's', 'q', 'm']), $row);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function trickyLiterals(): iterable
    {
        yield 'plain' => ['draft'];
        yield 'empty' => [''];
        yield 'single quote' => ["O'Reilly"];
        yield 'backslash' => ['C:\temp\new'];
        yield 'backslash before quote' => ["\\'"];
        yield 'trailing backslash' => ['end\\'];
        yield 'injection attempt' => ["'; DROP TABLE x; --"];
        yield 'multibyte and emoji' => ['Société générale 💶'];
        yield 'newline and tab' => ["a\nb\tc"];
    }

    // RG-01.4 : le littéral relu par le serveur est identique à la valeur d'origine
    #[DataProvider('trickyLiterals')]
    public function testStringLiteralRoundTrip(string $value): void
    {
        $sql = 'SELECT ' . $this->connection->getPlatform()->quoteStringLiteral($value);

        self::assertSame($value, $this->connection->executeQuery($sql)->fetchOne());
    }

    private function quote(string $name): string
    {
        return $this->connection->getPlatform()->quoteIdentifier($name);
    }

    private function dropTable(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->quote(self::TABLE));
    }
}
