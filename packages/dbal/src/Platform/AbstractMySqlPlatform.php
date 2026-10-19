<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidIdentifierException;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Table;

/**
 * Socle commun MySQL / MariaDB ; les divergences (VECTOR, JSON, index) vivent dans les sous-classes.
 *
 * @api
 */
abstract class AbstractMySqlPlatform extends AbstractPlatform
{
    /**
     * Le backslash est un caractère d'échappement avec le sql_mode par défaut : il est doublé.
     * Un serveur en NO_BACKSLASH_ESCAPES n'est pas encore géré (voir docs/BACKLOG.md).
     */
    public function quoteStringLiteral(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw InvalidParameterException::nulByteInLiteral();
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    public function getMaxIdentifierLength(): int
    {
        return 64;
    }

    // MySQL n'a pas de « LIMIT ALL » : la documentation recommande le plus grand entier non signé
    protected function getOffsetWithoutLimitSql(int $offset): string
    {
        return ' LIMIT 18446744073709551615 OFFSET ' . $offset;
    }

    // MySQL : la contrainte se supprime par DROP FOREIGN KEY, pas DROP CONSTRAINT
    public function getDropForeignKeySql(string $table, string $foreignKey): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP FOREIGN KEY ' . $this->quoteIdentifier($foreignKey);
    }

    // MySQL : un index appartient à sa table
    public function getDropIndexSql(string $table, string $index): string
    {
        return 'DROP INDEX ' . $this->quoteIdentifier($index) . ' ON ' . $this->quoteIdentifier($table);
    }

    public function getListTablesSql(): string
    {
        return "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
    }

    public function getListColumnsSql(): string
    {
        return 'SELECT c.COLUMN_NAME AS name, c.DATA_TYPE AS data_type, c.COLUMN_TYPE AS column_type, c.IS_NULLABLE AS nullable,'
            . ' c.COLUMN_DEFAULT AS default_value, c.CHARACTER_MAXIMUM_LENGTH AS length, c.NUMERIC_PRECISION AS `precision`,'
            . ' c.NUMERIC_SCALE AS scale, c.EXTRA AS extra, k.ORDINAL_POSITION AS primary_position'
            . ' FROM information_schema.COLUMNS c'
            . " LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME AND k.COLUMN_NAME = c.COLUMN_NAME AND k.CONSTRAINT_NAME = 'PRIMARY'"
            . ' WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = ? ORDER BY c.ORDINAL_POSITION';
    }

    public function getListIndexesSql(): string
    {
        return 'SELECT INDEX_NAME AS name, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME <> 'PRIMARY' ORDER BY INDEX_NAME, SEQ_IN_INDEX";
    }

    public function getListForeignKeysSql(): string
    {
        return 'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS local_column, k.REFERENCED_TABLE_NAME AS foreign_table,'
            . ' k.REFERENCED_COLUMN_NAME AS foreign_column, r.DELETE_RULE AS on_delete'
            . ' FROM information_schema.KEY_COLUMN_USAGE k'
            . ' JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME'
            . ' WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL'
            . ' ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION';
    }

    protected function getIdentifierQuoteCharacter(): string
    {
        return '`';
    }

    protected function getTypeDeclarationSql(Table $table, Column $column): string
    {
        return match ($column->type) {
            'string' => \sprintf('VARCHAR(%d)', $column->length ?? 255),
            // TEXT plafonne à 64 Ko ; LONGTEXT évite une troncature ou une erreur en mode strict
            'text' => 'LONGTEXT',
            'integer' => 'INT',
            'smallint' => 'SMALLINT',
            'bigint' => 'BIGINT',
            'boolean' => 'TINYINT(1)',
            'decimal' => \sprintf('DECIMAL(%d, %d)', $column->precision, $column->scale),
            'float' => 'DOUBLE',
            'datetime_immutable' => 'DATETIME',
            'date_immutable' => 'DATE',
            'time_immutable' => 'TIME',
            'json' => 'JSON',
            'uuid' => $this->getUuidDeclarationSql(),
            'binary' => null === $column->length ? 'LONGBLOB' : \sprintf('VARBINARY(%d)', $column->length),
            default => $this->unsupportedType($table, $column),
        };
    }

    protected function getUuidDeclarationSql(): string
    {
        return 'CHAR(36)';
    }

    protected function getAutoincrementSql(): string
    {
        return ' AUTO_INCREMENT';
    }

    // MySQL compte la limite en caractères, pas en octets
    protected function assertIdentifierLength(string $name): void
    {
        $length = preg_match_all('/./su', $name);
        if ($length > $this->getMaxIdentifierLength()) {
            throw InvalidIdentifierException::tooLong($this->getName(), $name, (int) $length, $this->getMaxIdentifierLength(), 'characters');
        }
    }
}
