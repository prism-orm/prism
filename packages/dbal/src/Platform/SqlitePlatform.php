<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Table;

/**
 * Tests et dev uniquement (périmètre 1.0).
 *
 * @api
 */
final class SqlitePlatform extends AbstractPlatform
{
    public function getName(): string
    {
        return 'SQLite';
    }

    public function getMinimumVersion(): string
    {
        return '3.40.0';
    }

    // SQLite n'a pas de type décimal : NUMERIC passe par un flottant (ou un entier) et perd
    // silencieusement les chiffres au-delà de 15 ; DecimalType refuse ces valeurs (RG-01.5)
    public function getDecimalMaxSignificantDigits(): int
    {
        return 15;
    }

    // SQLite : LIMIT -1 signifie « sans limite »
    protected function getOffsetWithoutLimitSql(int $offset): string
    {
        return ' LIMIT -1 OFFSET ' . $offset;
    }

    protected function getTypeDeclarationSql(Table $table, Column $column): string
    {
        if ($column->autoincrement) {
            // seul « INTEGER PRIMARY KEY » devient un alias de rowid auto-incrémenté
            return 'INTEGER';
        }

        return match ($column->type) {
            'string' => \sprintf('VARCHAR(%d)', $column->length ?? 255),
            'text' => 'TEXT',
            'integer' => 'INTEGER',
            'smallint' => 'SMALLINT',
            'bigint' => 'BIGINT',
            'boolean' => 'BOOLEAN',
            'decimal' => \sprintf('NUMERIC(%d, %d)', $column->precision, $column->scale),
            'float' => 'DOUBLE PRECISION',
            'datetime_immutable' => 'DATETIME',
            'date_immutable' => 'DATE',
            'time_immutable' => 'TIME',
            // « JSON » aurait l'affinité NUMERIC (le JSON 123 deviendrait un entier) ;
            // JSON_TEXT a l'affinité TEXT et reste distinct de text à l'introspection
            'json' => 'JSON_TEXT',
            'uuid' => 'CHAR(36)',
            'binary' => 'BLOB',
            default => $this->unsupportedType($table, $column),
        };
    }

    protected function getAutoincrementSql(): string
    {
        return ' PRIMARY KEY AUTOINCREMENT';
    }

    protected function declaresPrimaryKeyInline(Table $table): bool
    {
        foreach ($table->getColumns() as $column) {
            if ($column->autoincrement) {
                return true;
            }
        }

        return false;
    }

    // ALTER TABLE ne sait ni ajouter ni supprimer une contrainte : les clés étrangères sont
    // déclarées dans le CREATE TABLE, une modification passe par une reconstruction (RG-01.11)
    protected function supportsAlterForeignKeys(): bool
    {
        return false;
    }

    // SQLite ignore les clés étrangères tant qu'on ne les active pas, connexion par connexion
    public function getSessionInitSql(): array
    {
        return ['PRAGMA foreign_keys = ON'];
    }
}
