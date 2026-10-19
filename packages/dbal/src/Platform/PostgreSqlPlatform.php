<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Table;

/**
 * @api
 */
final class PostgreSqlPlatform extends AbstractPlatform
{
    public function getName(): string
    {
        return 'PostgreSQL';
    }

    public function getMinimumVersion(): string
    {
        return '15.0.0';
    }

    // au-delà, PostgreSQL tronque l'identifiant sans erreur : Prism refuse avant
    public function getMaxIdentifierLength(): int
    {
        return 63;
    }

    // valeur par défaut depuis 9.1, mais modifiable par l'administrateur ; quoteStringLiteral() en dépend
    public function getSessionInitSql(): array
    {
        return ['SET standard_conforming_strings = on'];
    }

    public function failedStatementAbortsTransaction(): bool
    {
        return true;
    }

    public function supportsSequences(): bool
    {
        return true;
    }

    // BYTEA n'a pas de longueur
    public function getCanonicalColumn(Column $column): Column
    {
        $column = parent::getCanonicalColumn($column);

        return 'binary' === $column->type && null !== $column->length ? $column->withType('binary') : $column;
    }

    public function getListTablesSql(): string
    {
        return "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name";
    }

    public function getListColumnsSql(): string
    {
        return 'SELECT c.column_name AS name, c.udt_name AS data_type, c.is_nullable AS nullable, c.column_default AS default_value,'
            . ' c.character_maximum_length AS length, c.numeric_precision AS precision, c.numeric_scale AS scale, c.is_identity AS is_identity,'
            . ' k.ordinal_position AS primary_position'
            . ' FROM information_schema.columns c'
            . " LEFT JOIN information_schema.table_constraints t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.constraint_type = 'PRIMARY KEY'"
            . ' LEFT JOIN information_schema.key_column_usage k ON k.constraint_schema = t.constraint_schema AND k.constraint_name = t.constraint_name AND k.column_name = c.column_name'
            . ' WHERE c.table_schema = current_schema() AND c.table_name = ? ORDER BY c.ordinal_position';
    }

    public function getListIndexesSql(): string
    {
        return 'SELECT i.relname AS name, a.attname AS column_name, CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS non_unique'
            . ' FROM pg_index ix'
            . ' JOIN pg_class t ON t.oid = ix.indrelid'
            . ' JOIN pg_class i ON i.oid = ix.indexrelid'
            . ' JOIN pg_namespace n ON n.oid = t.relnamespace'
            . ' CROSS JOIN LATERAL unnest(ix.indkey::int2[]) WITH ORDINALITY AS k(attnum, position)'
            . ' JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum'
            . ' WHERE n.nspname = current_schema() AND t.relname = ? AND NOT ix.indisprimary'
            . ' ORDER BY i.relname, k.position';
    }

    public function getListForeignKeysSql(): string
    {
        return 'SELECT c.conname AS name, a.attname AS local_column, ft.relname AS foreign_table, fa.attname AS foreign_column,'
            . " CASE c.confdeltype WHEN 'c' THEN 'CASCADE' WHEN 'n' THEN 'SET NULL' WHEN 'r' THEN 'RESTRICT' WHEN 'a' THEN 'NO ACTION' ELSE 'SET DEFAULT' END AS on_delete"
            . ' FROM pg_constraint c'
            . ' JOIN pg_class t ON t.oid = c.conrelid'
            . ' JOIN pg_namespace n ON n.oid = t.relnamespace'
            . ' JOIN pg_class ft ON ft.oid = c.confrelid'
            . ' CROSS JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(local_attnum, foreign_attnum, position)'
            . ' JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.local_attnum'
            . ' JOIN pg_attribute fa ON fa.attrelid = c.confrelid AND fa.attnum = k.foreign_attnum'
            . " WHERE c.contype = 'f' AND n.nspname = current_schema() AND t.relname = ?"
            . ' ORDER BY c.conname, k.position';
    }

    /**
     * Séquences autonomes : celles des colonnes d'identité (dépendance « i ») ou SERIAL (« a »)
     * appartiennent à leur colonne et sont exclues.
     *
     * @internal
     */
    public function getListSequencesSql(): string
    {
        return 'SELECT s.sequencename AS name, s.start_value, s.increment_by AS increment FROM pg_sequences s'
            . ' JOIN pg_namespace n ON n.nspname = s.schemaname'
            . ' JOIN pg_class c ON c.relname = s.sequencename AND c.relnamespace = n.oid'
            . " WHERE s.schemaname = current_schema() AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = c.oid AND d.deptype IN ('i', 'a'))"
            . ' ORDER BY s.sequencename';
    }

    protected function getTypeDeclarationSql(Table $table, Column $column): string
    {
        return match ($column->type) {
            'string' => \sprintf('VARCHAR(%d)', $column->length ?? 255),
            'text' => 'TEXT',
            'integer' => 'INTEGER',
            'smallint' => 'SMALLINT',
            'bigint' => 'BIGINT',
            'boolean' => 'BOOLEAN',
            'decimal' => \sprintf('NUMERIC(%d, %d)', $column->precision, $column->scale),
            'float' => 'DOUBLE PRECISION',
            'datetime_immutable' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'date_immutable' => 'DATE',
            'time_immutable' => 'TIME(0) WITHOUT TIME ZONE',
            'json' => 'JSONB',
            'uuid' => 'UUID',
            'binary' => 'BYTEA',
            default => $this->unsupportedType($table, $column),
        };
    }

    // identité SQL standard plutôt que SERIAL : la séquence appartient à la colonne
    protected function getAutoincrementSql(): string
    {
        return ' GENERATED BY DEFAULT AS IDENTITY';
    }

    protected function getBooleanLiteralSql(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }
}
