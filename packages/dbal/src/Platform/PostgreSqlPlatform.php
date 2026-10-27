<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ColumnDiff;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\VectorDistance;

/**
 * @api
 */
final class PostgreSqlPlatform extends AbstractPlatform
{
    /** Opclass HNSW de pgvector, par distance. */
    public const array OPERATOR_CLASSES = ['Cosine' => 'vector_cosine_ops', 'Euclidean' => 'vector_l2_ops', 'InnerProduct' => 'vector_ip_ops'];

    public const string DEFAULT_LANGUAGE = 'simple';

    public function getName(): string
    {
        return 'PostgreSQL';
    }

    public function getMinimumVersion(): string
    {
        return '15.0.0';
    }

    // au-delà, PostgreSQL tronque l'identifiant sans erreur : Prism refuse avant
    // colonne JSONB : une clé ou un index par argument, un index s'écrit comme une clé
    public function getJsonExtractTextSql(string $expression, array $path): string
    {
        $keys = array_map(fn (string|int $key): string => $this->quoteStringLiteral((string) $key), self::validJsonPath($path));

        return \sprintf('jsonb_extract_path_text(%s, %s)', $expression, implode(', ', $keys));
    }

    public function getCastSql(string $expression, string $type): string
    {
        return \sprintf('CAST(%s AS %s)', $expression, 'integer' === $type ? 'BIGINT' : 'NUMERIC');
    }

    public function supportsVectors(): bool
    {
        return true;
    }

    // un paramètre lié en texte reste de type inconnu dans une expression : converti explicitement
    public function getVectorParameterSql(string $placeholder): string
    {
        return 'CAST(' . $placeholder . ' AS vector)';
    }

    // <#> rend l'opposé du produit scalaire : une distance, comme les deux autres
    public function getVectorDistanceSql(VectorDistance $distance, string $column, string $vector): string
    {
        return \sprintf(match ($distance) {
            VectorDistance::Cosine => '(%s <=> %s)',
            VectorDistance::Euclidean => '(%s <-> %s)',
            VectorDistance::InnerProduct => '(%s <#> %s)',
        }, $column, $vector);
    }

    /**
     * L'extension pgvector, créée si besoin, puis sa version vérifiée (0.8 : parcours itératif
     * des index HNSW sous filtre, ADR 0017). Sans le droit de la créer, l'erreur de la base remonte.
     *
     * @return list<string>
     */
    public function getVectorExtensionSql(): array
    {
        $version = "(SELECT extversion FROM pg_extension WHERE extname = 'vector')";

        return [
            'CREATE EXTENSION IF NOT EXISTS vector',
            'DO $$ BEGIN IF string_to_array(' . $version . ", '.')::int[] < ARRAY[0, 8] THEN"
                . " RAISE EXCEPTION 'Prism needs pgvector 0.8 or later, found %', " . $version . '; END IF; END $$',
        ];
    }

    public function getCreateSchemaSql(Schema $schema): array
    {
        $sql = parent::getCreateSchemaSql($schema);

        return self::hasVector($schema->getTables()) ? [...$this->getVectorExtensionSql(), ...$sql] : $sql;
    }

    public function getMigrationSql(SchemaDiff $diff): array
    {
        $sql = parent::getMigrationSql($diff);
        $columns = [];
        foreach ($diff->alteredTables as $table) {
            array_push($columns, ...$table->addedColumns, ...array_map(static fn (ColumnDiff $c): Column => $c->to, $table->changedColumns));
        }
        $needed = self::hasVector($diff->createdTables) || [] !== array_filter($columns, static fn (Column $c): bool => 'vector' === $c->type);

        return $needed ? [...$this->getVectorExtensionSql(), ...$sql] : $sql;
    }

    public function getListDatabasesSql(): string
    {
        return 'SELECT datname FROM pg_database WHERE datistemplate = false';
    }

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

    // RG-06.5 : sans verrou des écritures ; interdit dans une transaction
    /**
     * HNSW avec l'opclass de la distance ; plein texte : index GIN sur l'expression du document,
     * que la recherche reprend à l'identique pour qu'il serve (getFullTextDocumentSql()).
     */
    public function getCreateIndexSql(string $table, Index $index): string
    {
        $index = $this->getCanonicalIndex($index);

        return match ($index->kind) {
            IndexKind::Regular => parent::getCreateIndexSql($table, $index),
            IndexKind::Vector => \sprintf(
                'CREATE INDEX %s ON %s USING hnsw (%s %s) WITH (m = %d, ef_construction = %d)',
                $this->quoteIdentifier($index->name),
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($index->columns[0]),
                self::OPERATOR_CLASSES[($index->distance ?? VectorDistance::Cosine)->name],
                $index->m,
                $index->efConstruction,
            ),
            IndexKind::FullText => \sprintf(
                'CREATE INDEX %s ON %s USING gin (%s)',
                $this->quoteIdentifier($index->name),
                $this->quoteIdentifier($table),
                $this->getFullTextDocumentSql(array_map($this->quoteIdentifier(...), $index->columns), $index->language),
            ),
        };
    }

    /**
     * Le document plein texte de colonnes (expressions SQL déjà citées) : celui de l'index GIN.
     *
     * @param list<string> $columns
     */
    public function getFullTextDocumentSql(array $columns, ?string $language): string
    {
        return \sprintf(
            'to_tsvector(%s, %s)',
            $this->quoteStringLiteral($language ?? self::DEFAULT_LANGUAGE),
            implode(" || ' ' || ", array_map(static fn (string $column): string => \sprintf("coalesce(%s, '')", $column), $columns)),
        );
    }

    // défauts de pgvector (m = 16, ef_construction = 64, au moins 2 × m) et configuration « simple »
    public function getCanonicalIndex(Index $index): Index
    {
        $m = $index->m ?? 16;

        return match ($index->kind) {
            IndexKind::Regular => $index,
            IndexKind::Vector => $index->withVectorOptions($m, $index->efConstruction ?? max(64, 2 * $m)),
            IndexKind::FullText => $index->withLanguage($index->language ?? self::DEFAULT_LANGUAGE),
        };
    }

    public function getCreateIndexConcurrentlySql(string $table, Index $index): string
    {
        return (string) preg_replace('/^CREATE (UNIQUE )?INDEX /', 'CREATE $1INDEX CONCURRENTLY ', $this->getCreateIndexSql($table, $index));
    }

    public function supportsConcurrentIndexCreation(): bool
    {
        return true;
    }

    // le DDL de PostgreSQL est transactionnel : une migration échouée est annulée entière
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    // nextval() reçoit le nom en texte : identifiant cité (casse, mots réservés) puis littéral
    public function getSequenceNextValSql(string $sequence): string
    {
        return 'SELECT nextval(' . $this->quoteStringLiteral($this->quoteIdentifier($sequence)) . ')';
    }

    /**
     * @param array<array-key, Table> $tables
     */
    private static function hasVector(array $tables): bool
    {
        foreach ($tables as $table) {
            foreach ($table->getColumns() as $column) {
                if ('vector' === $column->type) {
                    return true;
                }
            }
        }

        return false;
    }

    // BYTEA n'a pas de longueur, fixe ou non
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
            . ' k.ordinal_position AS primary_position, a.atttypmod AS type_modifier'
            . ' FROM information_schema.columns c'
            // la dimension d'un vector(n) n'est que dans le modificateur de type
            . ' JOIN pg_attribute a ON a.attrelid = (quote_ident(c.table_schema) || \'.\' || quote_ident(c.table_name))::regclass AND a.attname = c.column_name'
            . " LEFT JOIN information_schema.table_constraints t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.constraint_type = 'PRIMARY KEY'"
            . ' LEFT JOIN information_schema.key_column_usage k ON k.constraint_schema = t.constraint_schema AND k.constraint_name = t.constraint_name AND k.column_name = c.column_name'
            . ' WHERE c.table_schema = current_schema() AND c.table_name = ? ORDER BY c.ordinal_position';
    }

    /**
     * Une ligne par colonne de l'index ; column_name est NULL pour une expression (le document
     * d'un index plein texte), relue dans definition.
     */
    public function getListIndexesSql(): string
    {
        return 'SELECT i.relname AS name, a.attname AS column_name, CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS non_unique, pg_get_expr(ix.indpred, ix.indrelid) AS predicate,'
            . " am.amname AS method, oc.opcname AS operator_class, array_to_string(i.reloptions, ',') AS options, pg_get_indexdef(ix.indexrelid) AS definition"
            . ' FROM pg_index ix'
            . ' JOIN pg_class t ON t.oid = ix.indrelid'
            . ' JOIN pg_class i ON i.oid = ix.indexrelid'
            . ' JOIN pg_am am ON am.oid = i.relam'
            . ' JOIN pg_namespace n ON n.oid = t.relnamespace'
            . ' CROSS JOIN LATERAL unnest(ix.indkey::int2[], ix.indclass::oid[]) WITH ORDINALITY AS k(attnum, opclass, position)'
            . ' LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum AND k.attnum > 0'
            . ' LEFT JOIN pg_opclass oc ON oc.oid = k.opclass'
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
            'string' => \sprintf($column->fixed ? 'CHAR(%d)' : 'VARCHAR(%d)', $column->length ?? 255),
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
            // extension pgvector, créée par le DDL qui en a besoin (getVectorExtensionSql())
            'vector' => \sprintf('vector(%d)', $column->length),
            default => $this->unsupportedType($table, $column),
        };
    }

    /**
     * Une instruction par propriété. Le défaut est retiré avant un changement de type (il
     * pourrait ne pas se convertir) puis reposé ; USING convertit les données existantes,
     * et échoue sur une valeur incompatible (le linter des migrations le signalera).
     */
    protected function getChangeColumnSql(Table $table, ColumnDiff $diff): array
    {
        $alter = 'ALTER TABLE ' . $this->quoteIdentifier($table->name) . ' ALTER COLUMN ' . $this->quoteIdentifier($diff->to->name);
        $typeChanged = $diff->hasChanged('type') || $diff->hasChanged('length') || $diff->hasChanged('precision') || $diff->hasChanged('scale') || $diff->hasChanged('fixed');
        $sql = [];

        if (($typeChanged || $diff->hasChanged('default')) && null !== $diff->from->default) {
            $sql[] = $alter . ' DROP DEFAULT';
        }
        if ($typeChanged) {
            $type = $this->getTypeDeclarationSql($table, $diff->to);
            $sql[] = $alter . ' TYPE ' . $type . ' USING ' . $this->quoteIdentifier($diff->to->name) . '::' . $type;
        }
        if ($diff->hasChanged('nullable')) {
            $sql[] = $alter . ($diff->to->nullable ? ' DROP NOT NULL' : ' SET NOT NULL');
        }
        if (($typeChanged || $diff->hasChanged('default')) && null !== $diff->to->default) {
            $sql[] = $alter . ' SET DEFAULT ' . $this->getDefaultValueSql($diff->to->default);
        }
        if ($diff->hasChanged('autoincrement')) {
            $sql[] = $alter . ($diff->to->autoincrement ? ' ADD GENERATED BY DEFAULT AS IDENTITY' : ' DROP IDENTITY');
        }

        return $sql;
    }

    // nom par défaut de PostgreSQL : Prism ne nomme pas la clé primaire (une table créée hors de
    // Prism avec un autre nom de contrainte n'est pas couverte)
    protected function getDropPrimaryKeySql(Table $table): string
    {
        $name = substr($table->name, 0, $this->getMaxIdentifierLength() - 5) . '_pkey';

        return 'ALTER TABLE ' . $this->quoteIdentifier($table->name) . ' DROP CONSTRAINT ' . $this->quoteIdentifier($name);
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
