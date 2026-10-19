<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\LifecycleEvent;
use Prism\Orm\Mapping\TrackingPolicy;

/**
 * Description complète d'une entité, déduite de ses attributs. Immuable une fois construite.
 *
 * Seules les incohérences internes sont refusées ici ; les vérifications croisées (colonnes en
 * double, cible absente, mappedBy incohérent) relèvent du MetadataValidator.
 *
 * @template-covariant T of object
 *
 * @api
 */
final class ClassMetadata
{
    /** @var array<string, FieldMapping> */
    private readonly array $fields;

    /** @var array<string, EmbeddedMapping> */
    private readonly array $embedded;

    /** @var array<string, AssociationMapping> */
    private readonly array $associations;

    /** Reflection créée au premier usage, jamais sérialisée (reconstruite après le cache). */
    private readonly ReflectionCache $reflection;

    /**
     * @param class-string<T>                               $className
     * @param list<FieldMapping>                            $fields
     * @param list<EmbeddedMapping>                         $embedded
     * @param list<AssociationMapping>                      $associations
     * @param list<IndexMapping>                            $indexes
     * @param array<value-of<LifecycleEvent>, list<string>> $lifecycleCallbacks méthodes par événement
     * @param list<class-string>                            $entityListeners
     * @param array<string, string>                         $tableOptions
     * @param class-string|null                             $repositoryClass
     */
    public function __construct(
        public readonly string $className,
        public readonly string $tableName,
        public readonly IdMapping $id,
        array $fields,
        array $embedded = [],
        array $associations = [],
        public readonly array $indexes = [],
        public readonly ?string $versionField = null,
        public readonly array $lifecycleCallbacks = [],
        public readonly array $entityListeners = [],
        public readonly TrackingPolicy $trackingPolicy = TrackingPolicy::Implicit,
        public readonly bool $readOnly = false,
        public readonly ?string $repositoryClass = null,
        public readonly array $tableOptions = [],
    ) {
        $this->fields = self::byName($className, $fields, static fn (FieldMapping $f): string => $f->fieldName);
        $this->embedded = self::byName($className, $embedded, static fn (EmbeddedMapping $e): string => $e->fieldName);
        $this->associations = self::byName($className, $associations, static fn (AssociationMapping $a): string => $a->fieldName);
        $this->reflection = new ReflectionCache();

        foreach (array_merge(array_keys($this->embedded), array_keys($this->associations)) as $name) {
            if (isset($this->fields[$name]) || (isset($this->embedded[$name]) && isset($this->associations[$name]))) {
                throw MappingException::invalidField($className, $name, 'the property is mapped twice.');
            }
        }
        if (!isset($this->fields[$id->fieldName])) {
            throw MappingException::invalidField($className, $id->fieldName, 'the identifier must be a mapped scalar field.');
        }
        if (null !== $versionField && !isset($this->fields[$versionField])) {
            throw MappingException::invalidField($className, $versionField, 'the version field must be a mapped scalar field.');
        }
    }

    /**
     * Champ scalaire, ou champ d'embeddable par son chemin (« billingAddress.city »).
     */
    public function getField(string $path): FieldMapping
    {
        if (isset($this->fields[$path])) {
            return $this->fields[$path];
        }

        [$embedded, $field] = array_pad(explode('.', $path, 2), 2, '');
        if (isset($this->embedded[$embedded]) && $this->embedded[$embedded]->hasField($field)) {
            return $this->embedded[$embedded]->getField($field);
        }

        throw MappingException::unknownField($this->className, $path);
    }

    public function hasField(string $path): bool
    {
        [$embedded, $field] = array_pad(explode('.', $path, 2), 2, '');

        return isset($this->fields[$path]) || (isset($this->embedded[$embedded]) && $this->embedded[$embedded]->hasField($field));
    }

    /**
     * @return list<FieldMapping> champs scalaires de l'entité, hors embeddables
     */
    public function getFields(): array
    {
        return array_values($this->fields);
    }

    public function getIdField(): FieldMapping
    {
        return $this->fields[$this->id->fieldName];
    }

    /**
     * @return list<EmbeddedMapping>
     */
    public function getEmbedded(): array
    {
        return array_values($this->embedded);
    }

    /**
     * @return list<AssociationMapping>
     */
    public function getAssociations(): array
    {
        return array_values($this->associations);
    }

    public function hasAssociation(string $name): bool
    {
        return isset($this->associations[$name]);
    }

    public function getAssociation(string $name): AssociationMapping
    {
        return $this->associations[$name] ?? throw MappingException::unknownField($this->className, $name);
    }

    /**
     * Chemin du champ porté par une colonne (« total », « billingAddress.city »), ou null.
     */
    public function getFieldPathByColumn(string $column): ?string
    {
        foreach ($this->fields as $name => $field) {
            if (0 === strcasecmp($field->columnName, $column)) {
                return $name;
            }
        }
        foreach ($this->embedded as $name => $embedded) {
            foreach ($embedded->getFields() as $field) {
                if (0 === strcasecmp($field->columnName, $column)) {
                    return $name . '.' . $field->fieldName;
                }
            }
        }

        return null;
    }

    /**
     * Colonnes de la table de l'entité : champs, champs embarqués, colonnes de jointure possédées.
     *
     * @return list<string>
     */
    public function getColumnNames(): array
    {
        $columns = array_map(static fn (FieldMapping $f): string => $f->columnName, array_values($this->fields));
        foreach ($this->embedded as $embedded) {
            foreach ($embedded->getFields() as $field) {
                $columns[] = $field->columnName;
            }
        }
        foreach ($this->associations as $association) {
            if ($association->isOwningSide() && null !== $association->joinColumn) {
                $columns[] = $association->joinColumn;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    public function getLifecycleCallbacks(LifecycleEvent $event): array
    {
        return $this->lifecycleCallbacks[$event->value] ?? [];
    }

    /**
     * Type DBAL de chaque colonne portée par un champ ou un embeddable.
     *
     * @return array<string, string>
     */
    public function getColumnTypes(): array
    {
        $types = [];
        foreach ($this->fields as $field) {
            $types[$field->columnName] = $field->type;
        }
        foreach ($this->embedded as $embedded) {
            foreach ($embedded->getFields() as $field) {
                $types[$field->columnName] = $field->type;
            }
        }

        return $types;
    }

    /**
     * Valeurs PHP des colonnes de l'entité (champs et embeddables), enums ramenés à leur valeur.
     * La conversion vers les types de la base (decimal, dates…) reste à l'appelant ; les
     * relations arrivent en phase 4.
     *
     * @return array<string, mixed>
     */
    public function extractRow(object $entity): array
    {
        $row = [];
        foreach ($this->fields as $field) {
            $row[$field->columnName] = self::toColumn($field, $this->read($this->className, $field->fieldName, $entity));
        }
        foreach ($this->embedded as $embedded) {
            $value = $this->read($this->className, $embedded->fieldName, $entity);
            foreach ($embedded->getFields() as $field) {
                $row[$field->columnName] = \is_object($value) ? self::toColumn($field, $this->read($embedded->class, $field->fieldName, $value)) : null;
            }
        }

        return $row;
    }

    /**
     * Écrit les colonnes présentes dans $row (valeurs PHP) sur l'entité, propriétés readonly
     * comprises (RG-02.6). Un embeddable nullable dont toutes les colonnes sont NULL redevient
     * null (RG-02.5) ; sinon il est instancié sans son constructeur (RG-03.12).
     *
     * @param array<string, mixed> $row
     *
     * @throws MappingException valeur qui n'est pas un cas de l'enum du champ
     */
    public function hydrateRow(object $entity, array $row): void
    {
        foreach ($this->fields as $field) {
            if (\array_key_exists($field->columnName, $row)) {
                $this->property($this->className, $field->fieldName)->setValue($entity, $this->toFieldValue($field, $row[$field->columnName]));
            }
        }

        foreach ($this->embedded as $embedded) {
            $columns = array_filter($embedded->getFields(), static fn (FieldMapping $f): bool => \array_key_exists($f->columnName, $row));
            if ([] === $columns) {
                continue;
            }
            $allNull = [] === array_filter($columns, static fn (FieldMapping $f): bool => null !== $row[$f->columnName]);
            if ($allNull && $embedded->nullable) {
                $this->property($this->className, $embedded->fieldName)->setValue($entity, null);
                continue;
            }

            $value = new \ReflectionClass($embedded->class)->newInstanceWithoutConstructor();
            foreach ($columns as $field) {
                $this->property($embedded->class, $field->fieldName)->setValue($value, $this->toFieldValue($field, $row[$field->columnName]));
            }
            $this->property($this->className, $embedded->fieldName)->setValue($entity, $value);
        }
    }

    private static function toColumn(FieldMapping $field, mixed $value): mixed
    {
        return null !== $field->enumType && $value instanceof \BackedEnum ? $value->value : $value;
    }

    /**
     * Valeur PHP d'un champ à partir de la valeur de sa colonne (déjà convertie par son Type) :
     * l'enum est reconstruit, le reste est rendu tel quel.
     *
     * @internal partagé avec l'ArrayHydrator
     *
     * @throws MappingException valeur qui n'est pas un cas de l'enum du champ
     */
    public function toFieldValue(FieldMapping $field, mixed $value): mixed
    {
        if (null === $field->enumType || null === $value || $value instanceof \BackedEnum) {
            return $value;
        }
        if (!\is_int($value) && !\is_string($value)) {
            throw MappingException::invalidField($this->className, $field->fieldName, \sprintf('a value of type %s cannot be converted to enum %s.', get_debug_type($value), $field->enumType));
        }

        return $field->enumType::tryFrom($value)
            ?? throw MappingException::invalidField($this->className, $field->fieldName, \sprintf('%s is not a case of enum %s.', var_export($value, true), $field->enumType));
    }

    /**
     * Une propriété non initialisée (sans valeur par défaut) se lit comme null.
     *
     * @param class-string $class
     */
    private function read(string $class, string $property, object $object): mixed
    {
        $reflection = $this->property($class, $property);

        return $reflection->isInitialized($object) ? $reflection->getValue($object) : null;
    }

    /**
     * @param class-string $class
     */
    private function property(string $class, string $property): \ReflectionProperty
    {
        return $this->reflection->property($class, $property);
    }

    /**
     * @internal nombre de ReflectionProperty construites (tests du cache)
     */
    public function getReflectionCount(): int
    {
        return $this->reflection->count();
    }

    /**
     * @template M of object
     *
     * @param class-string        $class
     * @param list<M>             $items
     * @param \Closure(M): string $name
     *
     * @return array<string, M>
     */
    private static function byName(string $class, array $items, \Closure $name): array
    {
        $byName = [];
        foreach ($items as $item) {
            if (isset($byName[$name($item)])) {
                throw MappingException::invalidField($class, $name($item), 'the property is mapped twice.');
            }
            $byName[$name($item)] = $item;
        }

        return $byName;
    }
}
