<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema\Introspection;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractMySqlPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\VectorDistance;

/**
 * MySQL et MariaDB (information_schema).
 *
 * @internal
 */
final class MySqlIntrospector extends AbstractIntrospector
{
    /** @var array<string, true> colonnes LONGTEXT porteuses d'un CHECK (json_valid(…)) */
    private array $jsonColumns = [];

    /** @var array<string, string> colonne testée, par colonne virtuelle d'index partiel (en minuscules) */
    private array $liveColumns = [];

    public function __construct(Connection $connection, AbstractMySqlPlatform $platform, private readonly bool $mariaDb)
    {
        parent::__construct($connection, $platform);
    }

    public function listSequences(): array
    {
        if (!$this->platform instanceof MariaDbPlatform) {
            return [];
        }

        $sequences = [];
        foreach ($this->connection->executeQuery($this->platform->getListSequencesSql())->fetchFirstColumn() as $name) {
            $definition = $this->connection->executeQuery($this->platform->getSequenceDefinitionSql(self::string($name)))->fetchAssociative();
            $sequences[] = new Sequence(self::string($name), (int) self::nullableInt($definition['start_value'] ?? 1), (int) self::nullableInt($definition['increment'] ?? 1));
        }

        return $sequences;
    }

    protected function prepare(string $table): void
    {
        $this->jsonColumns = [];
        $this->liveColumns = [];
        $this->vectorIndexes = null;
        if (!$this->platform instanceof MariaDbPlatform) {
            return;
        }

        foreach ($this->connection->executeQuery($this->platform->getListCheckConstraintsSql(), [$table])->fetchAllAssociative() as $row) {
            if (1 === preg_match('/^json_valid\(`((?:[^`]|``)+)`\)$/i', self::string($row['clause']), $m)) {
                $this->jsonColumns[strtolower(str_replace('``', '`', $m[1]))] = true;
            }
        }
    }

    protected function column(string $table, array $row): Column
    {
        $name = self::string($row['name']);
        $dataType = strtolower(self::string($row['data_type']));
        $length = self::nullableInt($row['length']);

        $fixed = \in_array($dataType, ['char', 'binary'], true) && null !== $length;
        [$type, $length, $precision, $scale] = match (true) {
            'varchar' === $dataType => ['string', $length, null, null],
            'longtext' === $dataType => [isset($this->jsonColumns[strtolower($name)]) ? 'json' : 'text', null, null, null],
            // les colonnes créées par d'autres outils (db:import) : le type Prism le plus proche, sans perte
            \in_array($dataType, ['tinytext', 'text', 'mediumtext'], true) => ['text', null, null, null],
            'int' === $dataType, 'mediumint' === $dataType => ['integer', null, null, null],
            'smallint' === $dataType, 'year' === $dataType => ['smallint', null, null, null],
            'bigint' === $dataType => ['bigint', null, null, null],
            'tinyint' === $dataType && 'tinyint(1)' === strtolower(self::string($row['column_type'])) => ['boolean', null, null, null],
            'tinyint' === $dataType => ['smallint', null, null, null],
            'decimal' === $dataType => ['decimal', null, self::nullableInt($row['precision']), self::nullableInt($row['scale'])],
            'double' === $dataType, 'float' === $dataType => ['float', null, null, null],
            'datetime' === $dataType, 'timestamp' === $dataType => ['datetime_immutable', null, null, null],
            'date' === $dataType => ['date_immutable', null, null, null],
            'time' === $dataType => ['time_immutable', null, null, null],
            'json' === $dataType => ['json', null, null, null],
            'uuid' === $dataType, 'char' === $dataType && 36 === $length => ['uuid', null, null, null],
            'char' === $dataType => ['string', $length, null, null],
            'varbinary' === $dataType, 'binary' === $dataType => ['binary', $length, null, null],
            // la contrainte des valeurs est perdue, la colonne reste telle quelle tant qu'aucun ALTER ne la touche
            'enum' === $dataType => ['string', $length, null, null],
            \in_array($dataType, ['tinyblob', 'blob', 'mediumblob', 'longblob'], true) => ['binary', null, null, null],
            // MariaDB : la longueur lue est en octets (4 par composante), la dimension est dans « vector(n) »
            'vector' === $dataType => ['vector', self::vectorDimensions(self::string($row['column_type'])), null, null],
            default => throw InvalidSchemaException::unsupportedDatabaseType($this->platform->getName(), $table, $name, self::string($row['column_type'])),
        };

        if ('enum' === $dataType) {
            $this->note($table, $name, \sprintf(
                'The %s type %s is read as a string of %d characters: the database keeps the ENUM until a migration changes the column, which then becomes a VARCHAR(%d).',
                $this->platform->getName(),
                self::string($row['column_type']),
                $length,
                $length,
            ));
        }

        return new Column(
            $name,
            $type,
            'YES' === self::string($row['nullable']),
            // un défaut calculé (CURRENT_TIMESTAMP) n'est pas un littéral : non géré par Prism
            str_contains(strtolower(self::string($row['extra'])), 'default_generated') ? null : self::typedDefault($type, $this->defaultValue(self::nullableString($row['default_value']))),
            $length,
            $precision,
            $scale,
            str_contains(strtolower(self::string($row['extra'])), 'auto_increment'),
            $fixed && 'uuid' !== $type,
        );
    }

    /** @var array<string, array{?int, VectorDistance}>|null M et distance des index vectoriels, par nom en minuscules */
    private ?array $vectorIndexes = null;

    // FULLTEXT ; VECTOR (MariaDB) : M et DISTANCE ne se lisent que dans SHOW CREATE TABLE
    protected function refineIndex(string $table, Index $index, ?string $type): Index
    {
        if ('FULLTEXT' === strtoupper($type ?? '')) {
            return new Index($index->name, $index->columns, kind: IndexKind::FullText);
        }
        if ('VECTOR' !== strtoupper($type ?? '') || !$this->platform instanceof MariaDbPlatform) {
            return $index;
        }
        if (null === $this->vectorIndexes) {
            $this->vectorIndexes = [];
            $create = self::string($this->connection->executeQuery($this->platform->getShowCreateTableSql($table))->fetchAssociative()['Create Table'] ?? '');
            preg_match_all('/^\s*VECTOR (?:KEY|INDEX) `((?:[^`]|``)+)` \([^)]*\)([^\r\n]*)/mi', $create, $matches, \PREG_SET_ORDER);
            foreach ($matches as $m) {
                $options = $m[2];
                $mValue = 1 === preg_match('/(?:^|\s)`?M`?\s*=\s*(\d+)(?=\s|,|$)/i', $options, $mMatch) ? (int) $mMatch[1] : null;
                $distanceValue = 1 === preg_match('/(?:^|\s)`?DISTANCE`?\s*=\s*(cosine|euclidean)(?=\s|,|$)/i', $options, $distanceMatch) ? strtolower($distanceMatch[1]) : null;
                // sans DISTANCE, celle par défaut de MariaDB (mhnsw_default_distance)
                $this->vectorIndexes[strtolower(str_replace('``', '`', $m[1]))] = [$mValue, 'cosine' === $distanceValue ? VectorDistance::Cosine : VectorDistance::Euclidean];
            }
        }
        [$m, $distance] = $this->vectorIndexes[strtolower($index->name)] ?? [null, VectorDistance::Euclidean];

        return new Index($index->name, $index->columns, kind: IndexKind::Vector, distance: $distance, m: $m);
    }

    // un index qui finit par la colonne virtuelle d'un index partiel redevient partiel
    protected function indexes(string $table): array
    {
        return array_map(function (Index $index): Index {
            $last = strtolower($index->columns[\count($index->columns) - 1]);

            return \count($index->columns) > 1 && isset($this->liveColumns[$last])
                ? new Index($index->name, \array_slice($index->columns, 0, -1), $index->unique, $this->liveColumns[$last])
                : $index;
        }, parent::indexes($table));
    }

    // la colonne virtuelle d'un index partiel (ADR 0015) : masquée, sa condition est relue
    protected function hidden(array $row): bool
    {
        $name = self::string($row['name']);
        if (!str_starts_with(strtolower($name), AbstractMySqlPlatform::LIVE_PREFIX)) {
            return false;
        }
        $column = 1 === preg_match('/`((?:[^`]|``)+)`\s+is\s+null/i', self::nullableString($row['generation'] ?? null) ?? '', $m) ? str_replace('``', '`', $m[1]) : null;
        if (null !== $column) {
            $this->liveColumns[strtolower($name)] = $column;
        }

        return true;
    }

    // MySQL crée un index portant le nom de la clé étrangère quand aucun index ne la couvre :
    // il n'existe pas dans le schéma déclaré et rendrait le diff instable
    protected function finish(Table $table): Table
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            foreach ($table->getIndexes() as $index) {
                if (!$index->unique && 0 === strcasecmp($index->name, $foreignKey->name) && $index->columns === $foreignKey->localColumns) {
                    $table = $table->withoutIndex($index->name);
                }
            }
        }

        return $table;
    }

    // MySQL : valeur brute ; MariaDB : littéral SQL ('draft', NULL), backslashes échappés
    private static function vectorDimensions(string $columnType): ?int
    {
        return 1 === preg_match('/^vector\((\d+)\)$/i', $columnType, $m) ? (int) $m[1] : null;
    }

    private function defaultValue(?string $raw): ?string
    {
        if (null === $raw || !$this->mariaDb) {
            return $raw;
        }
        if ('NULL' === $raw) {
            return null;
        }
        if (self::isQuoted($raw)) {
            return stripcslashes(self::unquote($raw));
        }

        // sans apostrophes : un nombre, ou une expression (current_timestamp()) que Prism ne gère pas
        return is_numeric($raw) ? $raw : null;
    }
}
