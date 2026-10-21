<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Tests\Support\TestDatabase;

/**
 * Une table créée par un autre outil que Prism (db:import, RG-06.10) sous MySQL et MariaDB : chaque
 * type courant a son type Prism, sans perte ; les défauts que Prism ne gère pas (texte, JSON,
 * expressions comme CURRENT_TIMESTAMP) sont ignorés au lieu d'être lus comme des littéraux.
 */
final class ForeignSchemaTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->connection = TestDatabase::connection();
        if (!\in_array($this->connection->getPlatform()->getName(), ['MySQL', 'MariaDB'], true)) {
            self::markTestSkipped('MySQL and MariaDB types.');
        }
        $this->connection->executeStatement('DROP TABLE IF EXISTS foreign_types');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && \in_array($this->connection->getPlatform()->getName(), ['MySQL', 'MariaDB'], true)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS foreign_types');
        }
    }

    public function testCommonTypesAndUnmanagedDefaults(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE foreign_types (
                id INT NOT NULL PRIMARY KEY,
                small_text TINYTEXT, body TEXT, notes MEDIUMTEXT, code CHAR(3), photo BLOB, scan MEDIUMBLOB, digest BINARY(16),
                level TINYINT(4) DEFAULT 2, counter MEDIUMINT, born YEAR, ratio FLOAT,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, label VARCHAR(10) DEFAULT 'x'
            )
            SQL);

        $table = $this->connection->createSchemaManager()->introspectTable('foreign_types');
        $type = static fn (string $name): string => $table->getColumn($name)->type;

        self::assertSame(['text', 'text', 'text', 'string', 'binary', 'binary', 'binary'], array_map($type, ['small_text', 'body', 'notes', 'code', 'photo', 'scan', 'digest']));
        self::assertSame([3, 16], [$table->getColumn('code')->length, $table->getColumn('digest')->length]);
        self::assertSame(['smallint', 'integer', 'smallint', 'float', 'datetime_immutable'], array_map($type, ['level', 'counter', 'born', 'ratio', 'created_at']));
        self::assertSame(2, $table->getColumn('level')->default);
        self::assertNull($table->getColumn('created_at')->default, 'CURRENT_TIMESTAMP is an expression, not the string "current_timestamp()"');
        self::assertSame('x', $table->getColumn('label')->default);
    }

    public function testTextDefaultIsIgnored(): void
    {
        // MariaDB accepte un défaut sur TEXT ; MySQL seulement une expression entre parenthèses
        $default = 'MariaDB' === $this->connection->getPlatform()->getName() ? "DEFAULT ''" : "DEFAULT ('')";
        $this->connection->executeStatement(\sprintf('CREATE TABLE foreign_types (id INT NOT NULL PRIMARY KEY, notes MEDIUMTEXT %s)', $default));

        $column = $this->connection->createSchemaManager()->introspectTable('foreign_types')->getColumn('notes');

        self::assertSame('text', $column->type);
        self::assertNull($column->default, 'Prism manages no default on text: the database keeps its own');
        self::assertFalse(Column::acceptsDefault('text'));
    }
}
