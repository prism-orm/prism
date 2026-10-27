<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\LockMode;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ColumnDiff;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\TableDiff;
use Prism\Dbal\Schema\VectorDistance;

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

    // aucun verrou de ligne : une transaction en écriture verrouille toute la base (décision C)
    // json_extract rend la valeur JSON native (un nombre reste un nombre) : ramenée au texte, comme ailleurs
    public function getJsonExtractTextSql(string $expression, array $path): string
    {
        return \sprintf('CAST(json_extract(%s, %s) AS TEXT)', $expression, $this->getJsonPathLiteral($path));
    }

    public function getLockSql(LockMode $mode): string
    {
        return '';
    }

    // ni type ni index : le texte est stocké tel quel, les distances calculées en PHP (lecture complète)
    public function supportsVectors(): bool
    {
        return true;
    }

    public function getVectorParameterSql(string $placeholder): string
    {
        return $placeholder;
    }

    /**
     * Fonctions de SqliteVectorFunctions, enregistrées par la Connection.
     */
    public function getVectorDistanceSql(VectorDistance $distance, string $column, string $vector): string
    {
        return \sprintf(match ($distance) {
            VectorDistance::Cosine => 'prism_vec_cosine(%s, %s)',
            VectorDistance::Euclidean => 'prism_vec_euclidean(%s, %s)',
            VectorDistance::InnerProduct => 'prism_vec_inner(%s, %s)',
        }, $column, $vector);
    }

    // SQLite : LIMIT -1 signifie « sans limite »
    protected function getOffsetWithoutLimitSql(int $offset): string
    {
        return ' LIMIT -1 OFFSET ' . $offset;
    }

    // BLOB n'a pas de longueur, fixe ou non ; un auto-incrément est toujours relu INTEGER
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
        return 'SELECT l.name, i.name AS column_name, CASE WHEN l."unique" = 1 THEN 0 ELSE 1 END AS non_unique,'
            . " CASE WHEN l.partial = 1 THEN (SELECT s.sql FROM sqlite_master s WHERE s.type = 'index' AND s.name = l.name) END AS predicate FROM pragma_index_list(?) l"
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
            // CHAR(36) est le uuid : CHARACTER garde la chaîne fixe distincte à l'introspection
            'string' => \sprintf($column->fixed ? 'CHARACTER(%d)' : 'VARCHAR(%d)', $column->length ?? 255),
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
            // le texte « [0.5,-1.25,3] » (affinité TEXT) ; la dimension n'est pas vérifiée par SQLite
            'vector' => \sprintf('VECTOR_TEXT(%d)', $column->length),
            default => $this->unsupportedType($table, $column),
        };
    }

    protected function getAutoincrementSql(): string
    {
        return ' PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * ALTER TABLE natif quand il suffit (ajout d'une colonne nullable ou avec défaut, suppression
     * d'une colonne) ; sinon reconstruction de la table (RG-01.11), selon la procédure SQLite :
     * nouvelle table, copie des colonnes communes, suppression, renommage, index conservés.
     *
     * La reconstruction exige PRAGMA foreign_keys désactivé, ce qui est sans effet dans une
     * transaction : sinon le DROP TABLE déclencherait les ON DELETE CASCADE des tables enfants.
     * Exécuter ce SQL par SchemaManager::migrate(), qui s'en charge.
     */
    protected function getAlterColumnsSql(TableDiff $diff): array
    {
        return $this->needsRebuild($diff) ? $this->getRebuildTableSql($diff) : parent::getAlterColumnsSql($diff);
    }

    /**
     * @internal utilisé par SchemaManager::migrate()
     */
    public function getForeignKeysSql(bool $enabled): string
    {
        return 'PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF');
    }

    /**
     * @internal colonne « foreign_keys » : 1 ou 0
     */
    public function getForeignKeysStateSql(): string
    {
        return 'PRAGMA foreign_keys';
    }

    /**
     * @internal une ligne par violation (colonnes « table » et « parent »)
     */
    public function getForeignKeyCheckSql(): string
    {
        return 'PRAGMA foreign_key_check';
    }

    private function needsRebuild(TableDiff $diff): bool
    {
        foreach ($diff->addedColumns as $column) {
            if (!$column->nullable && null === $column->default) {
                return true;
            }
        }

        return [] !== $diff->changedColumns || $diff->primaryKeyChanged() || [] !== $diff->addedForeignKeys || [] !== $diff->droppedForeignKeys;
    }

    /**
     * @return list<string>
     */
    private function getRebuildTableSql(TableDiff $diff): array
    {
        $to = $diff->to;
        $temporary = '__prism_tmp_' . $to->name;
        $common = array_values(array_filter($to->getColumns(), static fn (Column $c): bool => $diff->from->hasColumn($c->name)));
        $columns = implode(', ', array_map(fn (Column $c): string => $this->quoteIdentifier($c->name), $common));

        $sql = [$this->getCreateTableSql(new Table($temporary, $to->getColumns(), $to->primaryKey, [], $to->getForeignKeys()))];
        if ([] !== $common) {
            $sql[] = \sprintf('INSERT INTO %s (%s) SELECT %s FROM %s', $this->quoteIdentifier($temporary), $columns, $columns, $this->quoteIdentifier($to->name));
        }
        $sql[] = $this->getDropTableSql($to->name);
        $sql[] = \sprintf('ALTER TABLE %s RENAME TO %s', $this->quoteIdentifier($temporary), $this->quoteIdentifier($to->name));

        // les index ajoutés sont créés plus loin par getMigrationSql() (étape 6 de RG-01.10)
        $added = array_map(static fn ($i): string => strtolower($i->name), $diff->addedIndexes);
        foreach ($to->getIndexes() as $index) {
            if (!\in_array(strtolower($index->name), $added, true)) {
                $sql[] = $this->getCreateIndexSql($to->name, $index);
            }
        }

        return $sql;
    }

    protected function getChangeColumnSql(Table $table, ColumnDiff $diff): array
    {
        throw InvalidSchemaException::unsupportedFeature($this->getName(), 'altering a column (the table must be rebuilt)');
    }

    protected function getDropPrimaryKeySql(Table $table): string
    {
        throw InvalidSchemaException::unsupportedFeature($this->getName(), 'dropping a primary key (the table must be rebuilt)');
    }

    protected function getDropForeignKeyStatements(TableDiff $table, ForeignKey $foreignKey): array
    {
        return [];
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
