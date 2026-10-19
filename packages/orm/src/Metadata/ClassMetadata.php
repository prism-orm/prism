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
 * @template T of object
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
