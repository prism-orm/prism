<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema\Introspection;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\AbstractMySqlPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

/**
 * MySQL et MariaDB (information_schema).
 *
 * @internal
 */
final class MySqlIntrospector extends AbstractIntrospector
{
    /** @var array<string, true> colonnes LONGTEXT porteuses d'un CHECK (json_valid(…)) */
    private array $jsonColumns = [];

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

        [$type, $length, $precision, $scale] = match (true) {
            'varchar' === $dataType => ['string', $length, null, null],
            'longtext' === $dataType => [isset($this->jsonColumns[strtolower($name)]) ? 'json' : 'text', null, null, null],
            'int' === $dataType => ['integer', null, null, null],
            'smallint' === $dataType => ['smallint', null, null, null],
            'bigint' === $dataType => ['bigint', null, null, null],
            'tinyint' === $dataType && 'tinyint(1)' === strtolower(self::string($row['column_type'])) => ['boolean', null, null, null],
            'decimal' === $dataType => ['decimal', null, self::nullableInt($row['precision']), self::nullableInt($row['scale'])],
            'double' === $dataType => ['float', null, null, null],
            'datetime' === $dataType => ['datetime_immutable', null, null, null],
            'date' === $dataType => ['date_immutable', null, null, null],
            'time' === $dataType => ['time_immutable', null, null, null],
            'json' === $dataType => ['json', null, null, null],
            'uuid' === $dataType, 'char' === $dataType && 36 === $length => ['uuid', null, null, null],
            'varbinary' === $dataType => ['binary', $length, null, null],
            'longblob' === $dataType => ['binary', null, null, null],
            default => throw InvalidSchemaException::unsupportedDatabaseType($this->platform->getName(), $table, $name, self::string($row['column_type'])),
        };

        return new Column(
            $name,
            $type,
            'YES' === self::string($row['nullable']),
            self::typedDefault($type, $this->defaultValue(self::nullableString($row['default_value']))),
            $length,
            $precision,
            $scale,
            str_contains(strtolower(self::string($row['extra'])), 'auto_increment'),
        );
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
    private function defaultValue(?string $raw): ?string
    {
        if (null === $raw || !$this->mariaDb) {
            return $raw;
        }
        if ('NULL' === $raw) {
            return null;
        }

        return self::isQuoted($raw) ? stripcslashes(self::unquote($raw)) : $raw;
    }
}
