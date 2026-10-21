<?php

declare(strict_types=1);

namespace Prism\Orm\Tools\Import;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Table;
use Prism\Orm\Metadata\Naming\NamingStrategy;
use Prism\Orm\Metadata\Naming\UnderscoreNamingStrategy;

/**
 * RG-06.10 : une entité final par table, typée strictement, relations déduites des clés
 * étrangères. Le mapping écrit est minimal (ce que les déductions de l'AttributeDriver ne
 * retrouvent pas) mais exact : les noms d'index, de contraintes uniques et de clés étrangères
 * sont repris, si bien que le schéma des entités est celui de la base, sans migration.
 *
 * Non importé, avec la raison : une table sans clé primaire, ou à clé composite (une entité a
 * exactement un identifiant en 1.0, RG-02.4). Une clé étrangère composite, ou vers une table non
 * importée, reste une colonne scalaire, signalée.
 *
 * @api
 */
final class EntityImporter
{
    /** @var list<string> mots réservés de PHP, interdits comme nom de classe */
    private const array RESERVED = ['abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enum', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'self', 'parent', 'bool', 'int', 'float', 'string', 'false', 'true', 'null', 'void', 'iterable', 'object', 'mixed', 'never'];

    /** @var array<string, string> types dont la propriété PHP suffit à le déduire (RG-02.1) */
    private const array INFERRED = ['string' => 'string', 'integer' => 'int', 'boolean' => 'bool', 'float' => 'float', 'datetime_immutable' => '\DateTimeImmutable', 'json' => 'array'];

    /** @var array<string, string> type de colonne → type PHP */
    private const array PHP_TYPES = [
        'string' => 'string', 'text' => 'string', 'uuid' => 'string', 'binary' => 'string', 'decimal' => 'string',
        'integer' => 'int', 'smallint' => 'int', 'bigint' => 'int', 'boolean' => 'bool', 'float' => 'float',
        'datetime_immutable' => '\DateTimeImmutable', 'date_immutable' => '\DateTimeImmutable', 'time_immutable' => '\DateTimeImmutable',
        'json' => 'array',
    ];

    private readonly NamingStrategy $naming;

    /** @var array<string, string> */
    private array $approximations = [];

    /** @var array<string, string> */
    private array $notes = [];

    public function __construct(?NamingStrategy $naming = null)
    {
        $this->naming = $naming ?? new UnderscoreNamingStrategy();
    }

    /**
     * @param array<string, string> $notes colonnes lues avec une perte, par « table.colonne »
     *                                     (SchemaManager::getIntrospectionNotes()) : signalées
     */
    public function import(Schema $schema, string $namespace, string $stripPrefix = '', array $notes = []): ImportResult
    {
        $this->notes = array_change_key_case($notes);
        $skipped = [];
        /** @var array<string, string> $classes table → classe (nom court) */
        $classes = [];
        foreach ($schema->getTables() as $table) {
            $reason = match (true) {
                null === $table->primaryKey => 'it has no primary key: an entity needs exactly one identifier (RG-02.4).',
                \count($table->primaryKey) > 1 => 'its primary key is composite: an entity has exactly one identifier in 1.0 (RG-02.4).',
                default => null,
            };
            if (null !== $reason) {
                $skipped[$table->name] = $reason;
                continue;
            }
            $class = self::className($table->name, $stripPrefix);
            if (\in_array($class, $classes, true)) {
                $skipped[$table->name] = \sprintf('its class name %s is already taken by the table %s.', $class, (string) array_search($class, $classes, true));
                continue;
            }
            $classes[$table->name] = $class;
        }
        ksort($skipped);

        $entities = [];
        $foreignKeys = [];
        $this->approximations = [];
        foreach ($schema->getTables() as $table) {
            if (isset($classes[$table->name])) {
                $entities[$classes[$table->name] . '.php'] = $this->entity($schema, $table, $namespace, $classes, $foreignKeys);
            }
        }
        ksort($entities);
        ksort($foreignKeys);
        ksort($this->approximations);

        return new ImportResult($entities, $skipped, $foreignKeys, $this->approximations);
    }

    /**
     * @param array<string, string> $classes
     * @param array<string, string> $foreignKeys
     */
    private function entity(Schema $schema, Table $table, string $namespace, array $classes, array &$foreignKeys): string
    {
        $id = strtolower(($table->primaryKey ?? [''])[0]);

        // les clés étrangères que porte une relation ; les autres restent des colonnes
        /** @var array<string, ForeignKey> $relations colonne → clé étrangère */
        $relations = [];
        foreach ($table->getForeignKeys() as $fk) {
            $target = $schema->hasTable($fk->foreignTable) ? $schema->getTable($fk->foreignTable) : null;
            $reason = match (true) {
                \count($fk->localColumns) > 1 => 'it is composite',
                null === $target || !isset(array_change_key_case($classes)[strtolower($target->name)]) => \sprintf('its table %s is not imported', $fk->foreignTable),
                [strtolower($fk->foreignColumns[0])] !== array_map(strtolower(...), $target->primaryKey ?? []) => 'it does not reference the primary key',
                strtolower($fk->localColumns[0]) === $id => 'its column is the primary key',
                default => null,
            };
            if (null === $reason) {
                $relations[strtolower($fk->localColumns[0])] = $fk;
            } else {
                $foreignKeys[$table->name . '.' . $fk->name] = \sprintf('%s → %s: %s; the column stays a scalar property.', implode(', ', $fk->localColumns), $fk->foreignTable, $reason);
            }
        }

        // un nom de propriété par colonne : la relation perd son suffixe _id
        $properties = [];
        foreach ($table->getColumns() as $column) {
            $key = strtolower($column->name);
            $name = self::camel(isset($relations[$key]) ? (string) preg_replace('/_id$/i', '', $column->name) : $column->name);
            while (\in_array($name, $properties, true)) {
                $name .= isset($relations[$key]) ? 'Ref' : 'Value';
            }
            $properties[$key] = $name;
        }

        $attributes = ['#[Prism\Entity]', \sprintf('#[Prism\Table(%s)]', self::export($table->name))];
        foreach ($table->getIndexes() as $index) {
            $attributes[] = \sprintf(
                '#[Prism\%s(%s, name: %s)]',
                $index->unique ? 'UniqueConstraint' : 'Index',
                self::list(array_map(static fn (string $c): string => $properties[strtolower($c)], $index->columns)),
                self::export($index->name),
            );
        }

        $members = [];
        foreach ($table->getColumns() as $column) {
            $key = strtolower($column->name);
            $member = isset($relations[$key])
                ? $this->relation($table, $column, $properties[$key], $relations[$key], $classes)
                : $this->field($column, $properties[$key], $key === $id);
            $note = $this->notes[strtolower($table->name . '.' . $column->name)] ?? null;
            if (null !== $note) {
                $this->approximate($table->name . '.' . $column->name, $note);
                $member = self::indent(['// approximation : ' . $note]) . "\n" . $member;
            }
            $members[] = $member;
        }

        $notes = [];
        foreach ($foreignKeys as $fk => $reason) {
            if (str_starts_with($fk, $table->name . '.')) {
                $notes[] = '// clé étrangère ' . substr($fk, \strlen($table->name) + 1) . ' gardée en colonne : ' . $reason;
            }
        }

        return \sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace %s;\n\nuse Prism\\Orm\\Mapping as Prism;\n\n// Importé par db:import depuis la table %s : à relire.\n%s%s\nfinal class %s\n{\n%s\n}\n",
            $namespace,
            $table->name,
            [] === $notes ? '' : implode("\n", $notes) . "\n",
            implode("\n", $attributes),
            $classes[$table->name],
            implode("\n\n", $members),
        );
    }

    private function field(Column $column, string $property, bool $id): string
    {
        $type = $column->type;
        $php = self::PHP_TYPES[$type] ?? 'mixed';
        $arguments = [];
        if ($this->naming->propertyToColumnName($property) !== $column->name) {
            $arguments[] = 'name: ' . self::export($column->name);
        }
        if (($this->inferredFrom($php) ?? null) !== $type) {
            $arguments[] = 'type: ' . self::export($type);
        }
        if (null !== $column->length) {
            $arguments[] = 'length: ' . $column->length;
        }
        if ($column->fixed) {
            $arguments[] = 'fixed: true';
        }
        if (null !== $column->precision) {
            $arguments[] = 'precision: ' . $column->precision;
        }
        if (null !== $column->scale) {
            $arguments[] = 'scale: ' . $column->scale;
        }
        if (null !== $column->default) {
            $arguments[] = 'default: ' . self::export($column->default);
        }

        $lines = [];
        if ($id) {
            $lines[] = $column->autoincrement ? '#[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]' : '#[Prism\Id]';
        }
        if ([] !== $arguments || !$id) {
            $lines[] = '#[Prism\Column' . ([] === $arguments ? '' : '(' . implode(', ', $arguments) . ')') . ']';
        }
        // un identifiant généré n'existe qu'après l'insertion : nul avant
        $nullable = $column->nullable || ($id && $column->autoincrement);
        $initial = match (true) {
            $nullable && (null === $column->default || '\DateTimeImmutable' === $php || 'mixed' === $php) => ' = null',
            null !== $column->default && \in_array($php, ['string', 'int', 'float', 'bool'], true) => ' = ' . self::export(self::phpDefault($php, $column->default)),
            default => '',
        };
        if ('array' === $php) {
            $lines[] = $nullable ? '/** @var array<mixed>|null */' : '/** @var array<mixed> */';
        }
        $lines[] = \sprintf('public %s%s $%s%s;', $nullable && 'mixed' !== $php ? '?' : '', $php, $property, $initial);

        return self::indent($lines);
    }

    /**
     * @param array<string, string> $classes
     */
    private function relation(Table $table, Column $column, string $property, ForeignKey $fk, array $classes): string
    {
        $unique = [] !== array_filter($table->getIndexes(), static fn (Index $i): bool => $i->unique && [strtolower($column->name)] === array_map(strtolower(...), $i->columns));
        $arguments = [];
        if ($this->naming->joinColumnName($property) !== $column->name) {
            $arguments[] = 'joinColumn: ' . self::export($column->name);
        }
        $onDelete = match ($fk->onDelete) {
            ForeignKeyAction::Cascade => 'Cascade',
            ForeignKeyAction::SetNull => 'SetNull',
            ForeignKeyAction::NoAction => 'NoAction',
            ForeignKeyAction::Restrict => null,
        };
        if (null !== $onDelete) {
            $arguments[] = 'onDelete: Prism\OnDelete::' . $onDelete;
        }
        $arguments[] = 'foreignKey: ' . self::export($fk->name);
        $target = array_change_key_case($classes)[strtolower($fk->foreignTable)] ?? '';
        $lines = [];
        // Prism indexe toute colonne de jointure ; sans index déclaré, le prochain diff en ajoute un
        $covered = [] !== array_filter($table->getIndexes(), static fn (Index $i): bool => 0 === strcasecmp($i->columns[0], $column->name));
        if (!$covered) {
            $reason = 'Prism indexes every join column: the next db:migrations:diff adds an index on it (recommended).';
            $this->approximate($table->name . '.' . $column->name, $reason);
            $lines[] = '// approximation : ' . $reason;
        }
        if (null !== $column->default) {
            $reason = \sprintf('The default %s of the join column cannot be mapped on a relation: the next db:migrations:diff will remove it; delete that changeColumn() from the migration to keep it.', self::export($column->default));
            $this->approximate($table->name . '.' . $column->name, $reason);
            $lines[] = '// approximation : ' . $reason;
        }

        return self::indent([...$lines,
            \sprintf('#[Prism\%s(%s)]', $unique ? 'OneToOne' : 'ManyToOne', implode(', ', $arguments)),
            \sprintf('public %s%s $%s%s;', $column->nullable ? '?' : '', $target, $property, $column->nullable ? ' = null' : ''),
        ]);
    }

    private function approximate(string $where, string $reason): void
    {
        $this->approximations[$where] = isset($this->approximations[$where]) ? $this->approximations[$where] . ' ' . $reason : $reason;
    }

    private function inferredFrom(string $php): ?string
    {
        $type = array_search($php, self::INFERRED, true);

        return false === $type ? null : $type;
    }

    private static function phpDefault(string $php, string|int|float|bool $default): string|int|float|bool
    {
        return match ($php) {
            'int' => (int) $default,
            'float' => (float) $default,
            'bool' => \is_bool($default) ? $default : \in_array(strtolower((string) $default), ['1', 'true', 't'], true),
            default => (string) $default,
        };
    }

    private static function className(string $table, string $stripPrefix): string
    {
        if ('' !== $stripPrefix && str_starts_with(strtolower($table), strtolower($stripPrefix))) {
            $table = substr($table, \strlen($stripPrefix));
        }
        $class = ucfirst(self::camel($table));
        if (1 === preg_match('/^\d/', $class)) {
            $class = 'T' . $class;
        }

        return \in_array(strtolower($class), self::RESERVED, true) ? $class . 'Entity' : $class;
    }

    private static function camel(string $name): string
    {
        $split = preg_split('/[^A-Za-z0-9]+/', $name);
        $parts = array_values(array_filter(false === $split ? [] : $split, static fn (string $p): bool => '' !== $p));
        if ([] === $parts) {
            return 'field';
        }
        $camel = strtolower($parts[0]) . implode('', array_map(static fn (string $p): string => ucfirst(strtolower($p)), \array_slice($parts, 1)));

        return 1 === preg_match('/^\d/', $camel) ? 'f' . $camel : $camel;
    }

    /**
     * @param list<string> $lines
     */
    private static function indent(array $lines): string
    {
        return implode("\n", array_map(static fn (string $line): string => '    ' . $line, $lines));
    }

    /**
     * @param list<string> $values
     */
    private static function list(array $values): string
    {
        return '[' . implode(', ', array_map(self::export(...), $values)) . ']';
    }

    private static function export(string|int|float|bool $value): string
    {
        return \is_bool($value) ? ($value ? 'true' : 'false') : var_export($value, true);
    }
}
