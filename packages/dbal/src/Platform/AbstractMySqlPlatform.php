<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidIdentifierException;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ColumnDiff;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\TableDiff;

/**
 * Socle commun MySQL / MariaDB ; les divergences (VECTOR, JSON, index) vivent dans les sous-classes.
 *
 * @api
 */
abstract class AbstractMySqlPlatform extends AbstractPlatform
{
    // colonnes virtuelles des index partiels (ADR 0015)
    public const string LIVE_PREFIX = 'prism_live_';

    // MySQL et MariaDB n'ont pas DEFAULT VALUES
    public function getInsertDefaultValuesSql(string $table): string
    {
        return 'INSERT INTO ' . $this->quoteIdentifier($table) . ' () VALUES ()';
    }

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

    // JSON_UNQUOTE rendrait « null » pour un null JSON : JSON_TYPE le ramène à NULL, comme ailleurs
    public function getJsonExtractTextSql(string $expression, array $path): string
    {
        $extract = \sprintf('JSON_EXTRACT(%s, %s)', $expression, $this->getJsonPathLiteral($path));

        return \sprintf("CASE JSON_TYPE(%s) WHEN 'NULL' THEN NULL ELSE JSON_UNQUOTE(%s) END", $extract, $extract);
    }

    // DECIMAL(65, 30) : la plus grande échelle de MySQL, sans perte ; la valeur garde 30 décimales
    public function getCastSql(string $expression, string $type): string
    {
        return \sprintf('CAST(%s AS %s)', $expression, 'integer' === $type ? 'SIGNED' : 'DECIMAL(65, 30)');
    }

    public function getListDatabasesSql(): string
    {
        return 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA';
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

    /**
     * Pas d'index partiel : une colonne virtuelle vaut 1 pour une ligne couverte, NULL sinon, et
     * finit l'index ; les NULL ne se heurtent pas dans un index unique (RG-08.15, ADR 0015).
     */
    public function getCreateIndexSql(string $table, Index $index): string
    {
        if (IndexKind::FullText === $index->kind) {
            return \sprintf('CREATE FULLTEXT INDEX %s ON %s (%s)', $this->quoteIdentifier($index->name), $this->quoteIdentifier($table), $this->quoteIdentifiers($index->columns));
        }
        if (IndexKind::Vector === $index->kind) {
            return $this->getCreateVectorIndexSql($table, $index);
        }
        if (null === $index->whereNull) {
            return parent::getCreateIndexSql($table, $index);
        }
        $live = self::liveColumn($index->name);

        return \sprintf(
            'ALTER TABLE %s ADD COLUMN %s TINYINT AS (IF(%s IS NULL, 1, NULL)) VIRTUAL, ADD %sINDEX %s (%s)',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($live),
            $this->quoteIdentifier($index->whereNull),
            $index->unique ? 'UNIQUE ' : '',
            $this->quoteIdentifier($index->name),
            $this->quoteIdentifiers([...$index->columns, $live]),
        );
    }

    public function getDropIndexDefinitionSql(string $table, Index $index): string
    {
        if (null === $index->whereNull) {
            return parent::getDropIndexDefinitionSql($table, $index);
        }

        return \sprintf('ALTER TABLE %s DROP INDEX %s, DROP COLUMN %s', $this->quoteIdentifier($table), $this->quoteIdentifier($index->name), $this->quoteIdentifier(self::liveColumn($index->name)));
    }

    /**
     * La colonne virtuelle d'un index partiel, déduite de son nom ; masquée par l'introspection.
     */
    public static function liveColumn(string $index): string
    {
        return self::LIVE_PREFIX . substr(hash('sha256', strtolower($index)), 0, 16);
    }

    public function getListTablesSql(): string
    {
        return "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
    }

    public function getListColumnsSql(): string
    {
        return 'SELECT c.COLUMN_NAME AS name, c.DATA_TYPE AS data_type, c.COLUMN_TYPE AS column_type, c.IS_NULLABLE AS nullable,'
            . ' c.COLUMN_DEFAULT AS default_value, c.CHARACTER_MAXIMUM_LENGTH AS length, c.NUMERIC_PRECISION AS `precision`,'
            . ' c.NUMERIC_SCALE AS scale, c.EXTRA AS extra, c.GENERATION_EXPRESSION AS generation, k.ORDINAL_POSITION AS primary_position'
            . ' FROM information_schema.COLUMNS c'
            . " LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME AND k.COLUMN_NAME = c.COLUMN_NAME AND k.CONSTRAINT_NAME = 'PRIMARY'"
            . ' WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = ? ORDER BY c.ORDINAL_POSITION';
    }

    public function getListIndexesSql(): string
    {
        return 'SELECT INDEX_NAME AS name, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, NULL AS predicate, INDEX_TYPE AS index_type FROM information_schema.STATISTICS'
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
            'string' => \sprintf($column->fixed ? 'CHAR(%d)' : 'VARCHAR(%d)', $column->length ?? 255),
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
            'binary' => null === $column->length ? 'LONGBLOB' : \sprintf($column->fixed ? 'BINARY(%d)' : 'VARBINARY(%d)', $column->length),
            'vector' => $this->getVectorDeclarationSql($column),
            default => $this->unsupportedType($table, $column),
        };
    }

    // mode langage naturel : un OU pondéré, servi par l'index FULLTEXT de ces colonnes (InnoDB)
    public function getFullTextScoreSql(array $columns, ?string $language, string $query): string
    {
        return \sprintf('MATCH (%s) AGAINST (%s IN NATURAL LANGUAGE MODE)', implode(', ', $columns), $query);
    }

    public function getFullTextConditionSql(array $columns, ?string $language, string $query): string
    {
        return $this->getFullTextScoreSql($columns, $language, $query);
    }

    // FULLTEXT n'a pas de configuration de langue ; MariaDB ne garde pas efConstruction
    public function getCanonicalIndex(Index $index): ?Index
    {
        return match ($index->kind) {
            IndexKind::FullText => $index->withLanguage(null),
            IndexKind::Vector => $index->withVectorOptions($index->m, null),
            IndexKind::Regular => $index,
        };
    }

    /**
     * @throws InvalidSchemaException MySQL : ni distance ni index vectoriel en Community (ADR 0017)
     */
    protected function getCreateVectorIndexSql(string $table, Index $index): string
    {
        throw InvalidSchemaException::unsupportedVector($this->getName());
    }

    /**
     * @throws InvalidSchemaException MySQL : ni distance ni index vectoriel en Community (ADR 0017)
     */
    protected function getVectorDeclarationSql(Column $column): string
    {
        throw InvalidSchemaException::unsupportedVector($this->getName());
    }

    // MODIFY redéclare la colonne entière : type, NULL, DEFAULT et AUTO_INCREMENT d'un coup
    protected function getChangeColumnSql(Table $table, ColumnDiff $diff): array
    {
        return ['ALTER TABLE ' . $this->quoteIdentifier($table->name) . ' MODIFY COLUMN ' . $this->getColumnDeclarationSql($table, $diff->to)];
    }

    protected function getDropPrimaryKeySql(Table $table): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table->name) . ' DROP PRIMARY KEY';
    }

    /**
     * MySQL a créé un index portant le nom de la clé étrangère si aucun index déclaré ne la
     * couvrait ; il survit à la clé et doit être supprimé avec elle, sinon le diff ne serait
     * jamais vide.
     */
    protected function getDropForeignKeyStatements(TableDiff $table, ForeignKey $foreignKey): array
    {
        $sql = [$this->getDropForeignKeySql($table->name, $foreignKey->name)];
        if (!self::isCoveredByIndex($table->from, $foreignKey)) {
            $sql[] = $this->getDropIndexSql($table->name, $foreignKey->name);
        }

        return $sql;
    }

    // un index (ou la clé primaire) dont les premières colonnes sont celles de la clé étrangère
    private static function isCoveredByIndex(Table $table, ForeignKey $foreignKey): bool
    {
        $columns = array_map(strtolower(...), $foreignKey->localColumns);
        $candidates = array_map(static fn ($index): array => $index->columns, $table->getIndexes());
        if (null !== $table->primaryKey) {
            $candidates[] = $table->primaryKey;
        }

        foreach ($candidates as $candidate) {
            if (\array_slice(array_map(strtolower(...), $candidate), 0, \count($columns)) === $columns) {
                return true;
            }
        }

        return false;
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
