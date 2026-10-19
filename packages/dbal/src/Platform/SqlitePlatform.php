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

    // BLOB n'a pas de longueur ; un auto-incrément est toujours relu INTEGER
    public function getCanonicalColumn(Column $column): Column
    {
        $column = parent::getCanonicalColumn($column);
        if ('binary' === $column->type && null !== $column->length) {
            return $column->withType('binary');
        }

        return $column->autoincrement && 'integer' !== $column->type ? $column->withType('integer') : $column;
    }

    public function getListTablesSql(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name";
    }

    public function getListColumnsSql(): string
    {
        return 'SELECT name, type AS data_type, "notnull" AS not_null, dflt_value AS default_value, pk AS primary_position FROM pragma_table_info(?) ORDER BY cid';
    }

    // origin « c » : index créés par CREATE INDEX (pas ceux implicites des clés primaires et UNIQUE)
    public function getListIndexesSql(): string
    {
        return 'SELECT l.name, i.name AS column_name, CASE WHEN l."unique" = 1 THEN 0 ELSE 1 END AS non_unique FROM pragma_index_list(?) l'
            . " JOIN pragma_index_info(l.name) i WHERE l.origin = 'c' ORDER BY l.name, i.seqno";
    }

    // le nom des contraintes n'est pas exposé par le pragma : il est relu dans le CREATE TABLE
    public function getListForeignKeysSql(): string
    {
        return 'SELECT id, "table" AS foreign_table, "from" AS local_column, "to" AS foreign_column, on_delete FROM pragma_foreign_key_list(?) ORDER BY id, seq';
    }

    /**
     * @internal un paramètre lié : le nom de la table
     */
    public function getTableDefinitionSql(): string
    {
        return "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?";
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
