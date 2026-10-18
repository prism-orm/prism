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
