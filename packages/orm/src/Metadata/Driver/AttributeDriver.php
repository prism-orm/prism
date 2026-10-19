<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata\Driver;

use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping as Attr;
use Prism\Orm\Mapping\LifecycleCallbackAttribute;
use Prism\Orm\Mapping\MappingAttribute;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\EmbeddedMapping;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\IdMapping;
use Prism\Orm\Metadata\IndexMapping;
use Prism\Orm\Metadata\JoinTableMapping;
use Prism\Orm\Metadata\Naming\NamingStrategy;
use Prism\Orm\Metadata\Naming\UnderscoreNamingStrategy;

/**
 * Construit les ClassMetadata à partir des attributs PHP (ADR 0002).
 *
 * Déductions : type de colonne depuis le type PHP (RG-02.1), nullabilité (RG-02.3), propriétés
 * readonly (RG-02.6) ; une propriété sans attribut n'est jamais touchée (RG-02.7).
 *
 * Ce driver refuse ce qui empêche de construire une metadata (type indéductible, \DateTime,
 * nullabilité contradictoire…) ; les vérifications entre entités relèvent du MetadataValidator.
 *
 * @api
 */
final class AttributeDriver implements MetadataDriver
{
    private const array SCALAR_TYPES = ['string' => 'string', 'int' => 'integer', 'bool' => 'boolean', 'float' => 'float', 'array' => 'json'];

    /**
     * @param list<string> $directories dossiers où chercher les entités (getAllClassNames)
     */
    public function __construct(
        private readonly array $directories = [],
        private readonly NamingStrategy $naming = new UnderscoreNamingStrategy(),
    ) {
    }

    public function isEntity(string $class): bool
    {
        return class_exists($class) && [] !== new \ReflectionClass($class)->getAttributes(Attr\Entity::class);
    }

    public function getAllClassNames(): array
    {
        $entities = [];
        foreach (ClassLocator::find($this->directories) as $class) {
            if (class_exists($class) && $this->isEntity($class)) {
                $entities[] = $class;
            }
        }

        return $entities;
    }

    public function loadMetadata(string $class): ClassMetadata
    {
        if (!class_exists($class)) {
            throw MappingException::unknownClass($class);
        }
        $reflection = new \ReflectionClass($class);
        $entity = self::attribute($reflection, Attr\Entity::class) ?? throw MappingException::notAnEntity($class);
        $table = self::attribute($reflection, Attr\Table::class);
        $this->assertNoMappedParent($reflection);

        $fields = [];
        $embedded = [];
        $associations = [];
        $id = null;
        $version = null;

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $name = $property->getName();

            if (null !== $association = $this->association($reflection, $property)) {
                $associations[] = $association;
            } elseif (null !== $embeddable = self::attribute($property, Attr\Embedded::class)) {
                $embedded[] = $this->embedded($reflection, $property, $embeddable);
            } elseif ($this->isField($property)) {
                $isId = null !== self::attribute($property, Attr\Id::class);
                $fields[] = $this->field($reflection, $property, $isId, '');
                if ($isId) {
                    if (null !== $id) {
                        throw MappingException::invalidMetadata($class, \sprintf('only one #[Id] is supported (found $%s and $%s); composite keys are out of scope for 1.0.', $id->fieldName, $name));
                    }
                    $generated = self::attribute($property, Attr\GeneratedValue::class);
                    $id = new IdMapping($name, $generated?->strategy, $generated?->sequenceName);
                }
                if (null !== self::attribute($property, Attr\Version::class)) {
                    $version = $name;
                }
            }
        }

        return new ClassMetadata(
            $class,
            $table->name ?? $this->naming->classToTableName($class),
            $id ?? throw MappingException::invalidMetadata($class, 'an entity needs exactly one #[Id] property.'),
            $fields,
            $embedded,
            $associations,
            $this->indexes($reflection),
            $version,
            $this->lifecycleCallbacks($reflection),
            self::attribute($reflection, Attr\EntityListeners::class)->listeners ?? [],
            $entity->trackingPolicy,
            $entity->readOnly,
            self::classString($class, $entity->repositoryClass),
            $table->options ?? [],
        );
    }

    private function isField(\ReflectionProperty $property): bool
    {
        return null !== self::attribute($property, Attr\Column::class)
            || null !== self::attribute($property, Attr\Id::class)
            || null !== self::attribute($property, Attr\Version::class);
    }

    /**
     * @param \ReflectionClass<object> $owner
     */
    private function field(\ReflectionClass $owner, \ReflectionProperty $property, bool $isId, string $columnPrefix, bool $forceNullable = false): FieldMapping
    {
        $class = $owner->getName();
        $name = $property->getName();
        $column = self::attribute($property, Attr\Column::class) ?? new Attr\Column();
        [$type, $enumType] = $this->columnType($class, $property, $column);

        $phpNullable = $property->getType()?->allowsNull() ?? true;
        if ($isId && true === $column->nullable) {
            throw MappingException::invalidField($class, $name, 'the identifier cannot be nullable.');
        }
        // un identifiant généré est null avant l'INSERT : ?int est permis, la colonne reste NOT NULL
        if (!$isId && false === $column->nullable && $phpNullable) {
            throw MappingException::invalidField($class, $name, \sprintf('#[Column(nullable: false)] contradicts the nullable PHP type "%s"; remove "?" or the nullable argument.', $property->getType()));
        }
        if (true === $column->nullable && !$phpNullable) {
            throw MappingException::invalidField($class, $name, \sprintf('#[Column(nullable: true)] cannot be hydrated into the non-nullable PHP type "%s"; make it "?%s".', $property->getType(), $property->getType()));
        }

        return new FieldMapping(
            $name,
            $columnPrefix . ($column->name ?? $this->naming->propertyToColumnName($name)),
            $type,
            $forceNullable || (!$isId && ($column->nullable ?? $phpNullable)),
            $column->length,
            $column->precision,
            $column->scale,
            $column->unique,
            self::defaultValue($class, $name, $column->default),
            $enumType,
            $property->isReadOnly(),
            $column->insertable,
            $column->updatable,
            $column->sensitive,
            $column->lazy,
        );
    }

    /**
     * RG-02.1 : type déclaré, sinon déduit du type PHP.
     *
     * @return array{string, class-string<\BackedEnum>|null}
     */
    private function columnType(string $class, \ReflectionProperty $property, Attr\Column $column): array
    {
        $name = $property->getName();
        $phpType = $property->getType();
        $typeName = $phpType instanceof \ReflectionNamedType ? $phpType->getName() : null;

        if (null !== $typeName && is_a($typeName, \DateTime::class, true)) {
            throw MappingException::mutableDateTime($class, $name);
        }
        if (null !== $column->type) {
            return [$column->type, $column->enumType];
        }
        if (null === $typeName) {
            throw MappingException::invalidField($class, $name, null === $phpType ? 'the column type cannot be inferred without a PHP type; declare one or set #[Column(type: ...)].' : \sprintf('the column type cannot be inferred from the union or intersection type "%s"; set #[Column(type: ...)].', $phpType));
        }

        return match (true) {
            isset(self::SCALAR_TYPES[$typeName]) => [self::SCALAR_TYPES[$typeName], $column->enumType],
            is_a($typeName, \DateTimeImmutable::class, true) => ['datetime_immutable', $column->enumType],
            enum_exists($typeName) && is_a($typeName, \BackedEnum::class, true) => [
                'int' === (string) new \ReflectionEnum($typeName)->getBackingType() ? 'integer' : 'string',
                $column->enumType ?? $typeName,
            ],
            default => throw MappingException::invalidField($class, $name, \sprintf('the column type cannot be inferred from PHP type "%s"; set #[Column(type: ...)] or map it as a relation or an embeddable.', $typeName)),
        };
    }

    /**
     * @param \ReflectionClass<object> $owner
     */
    private function embedded(\ReflectionClass $owner, \ReflectionProperty $property, Attr\Embedded $attribute): EmbeddedMapping
    {
        $class = $owner->getName();
        $name = $property->getName();
        $phpType = $property->getType();
        $embeddedClass = $attribute->class ?? ($phpType instanceof \ReflectionNamedType && !$phpType->isBuiltin() ? $phpType->getName() : null);

        if (null === $embeddedClass || !class_exists($embeddedClass)) {
            throw MappingException::invalidField($class, $name, 'the embeddable class cannot be determined; type the property or set #[Embedded(class: ...)].');
        }
        $embeddable = new \ReflectionClass($embeddedClass);
        if (null === self::attribute($embeddable, Attr\Embeddable::class)) {
            throw MappingException::invalidField($class, $name, \sprintf('%s is not an embeddable: add #[Embeddable] to the class.', $embeddedClass));
        }

        $prefix = match (true) {
            false === $attribute->columnPrefix => '',
            \is_string($attribute->columnPrefix) => $attribute->columnPrefix,
            default => $this->naming->embeddedColumnPrefix($name),
        };
        // RG-02.5 : un embeddable nullable a toutes ses colonnes nullables
        $nullable = $phpType?->allowsNull() ?? false;

        $fields = [];
        foreach ($embeddable->getProperties() as $embeddedProperty) {
            if (null !== self::attribute($embeddedProperty, Attr\Embedded::class)) {
                throw MappingException::invalidField($embeddedClass, $embeddedProperty->getName(), 'nested embeddables are not supported.');
            }
            if (null !== self::attribute($embeddedProperty, Attr\Column::class)) {
                $fields[] = $this->field($embeddable, $embeddedProperty, false, $prefix, $nullable);
            }
        }

        return new EmbeddedMapping($name, $embeddedClass, $prefix, $nullable, $fields);
    }

    /**
     * @param \ReflectionClass<object> $owner
     */
    private function association(\ReflectionClass $owner, \ReflectionProperty $property): ?AssociationMapping
    {
        $class = $owner->getName();
        $name = $property->getName();
        $phpType = $property->getType();
        $declared = $phpType instanceof \ReflectionNamedType && !$phpType->isBuiltin() ? $phpType->getName() : null;
        $orderBy = self::attribute($property, Attr\OrderBy::class)->fields ?? [];

        if (null !== $a = self::attribute($property, Attr\ManyToOne::class)) {
            $target = $this->target($class, $name, $a->targetEntity ?? $declared);

            return new AssociationMapping($class, $name, AssociationType::ManyToOne, $target, null, $a->inversedBy, $a->joinColumn ?? $this->naming->joinColumnName($name), $phpType?->allowsNull() ?? true, $a->onDelete, null, $a->cascade, $a->fetch, false, null, [], $a->batchSize, $a->batch);
        }
        if (null !== $a = self::attribute($property, Attr\OneToOne::class)) {
            $target = $this->target($class, $name, $a->targetEntity ?? $declared);
            $joinColumn = null === $a->mappedBy ? ($a->joinColumn ?? $this->naming->joinColumnName($name)) : null;

            return new AssociationMapping($class, $name, AssociationType::OneToOne, $target, $a->mappedBy, $a->inversedBy, $joinColumn, $phpType?->allowsNull() ?? true, $a->onDelete, null, $a->cascade, $a->fetch, $a->orphanRemoval, null, [], $a->batchSize, $a->batch);
        }
        if (null !== $a = self::attribute($property, Attr\OneToMany::class)) {
            return new AssociationMapping($class, $name, AssociationType::OneToMany, $this->target($class, $name, $a->targetEntity), $a->mappedBy, null, null, true, Attr\OnDelete::Restrict, null, $a->cascade, $a->fetch, $a->orphanRemoval, $a->indexBy, $orderBy, $a->batchSize, $a->batch);
        }
        if (null !== $a = self::attribute($property, Attr\ManyToMany::class)) {
            $target = $this->target($class, $name, $a->targetEntity);
            $joinTable = null;
            if (null === $a->mappedBy) {
                $declaredTable = self::attribute($property, Attr\JoinTable::class);
                $joinTable = new JoinTableMapping(
                    $declaredTable->name ?? $this->naming->joinTableName($class, $target),
                    $declaredTable->joinColumn ?? $this->naming->joinKeyColumnName($class),
                    $declaredTable->inverseJoinColumn ?? $this->naming->joinKeyColumnName($target),
                    $declaredTable->onDelete ?? Attr\OnDelete::Cascade,
                );
            }

            return new AssociationMapping($class, $name, AssociationType::ManyToMany, $target, $a->mappedBy, $a->inversedBy, null, true, Attr\OnDelete::Restrict, $joinTable, $a->cascade, $a->fetch, false, $a->indexBy, $orderBy, $a->batchSize, $a->batch);
        }

        return null;
    }

    /**
     * @return class-string
     */
    private function target(string $class, string $field, ?string $target): string
    {
        if (null === $target) {
            throw MappingException::invalidField($class, $field, 'the target entity cannot be determined; type the property or set targetEntity.');
        }
        if (!class_exists($target)) {
            throw MappingException::invalidField($class, $field, \sprintf('the target entity %s does not exist.', $target));
        }

        return $target;
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<IndexMapping>
     */
    private function indexes(\ReflectionClass $class): array
    {
        $indexes = [];
        foreach ($class->getAttributes(Attr\Index::class) as $attribute) {
            $index = $attribute->newInstance();
            $indexes[] = new IndexMapping($index->fields, false, $index->name, $index->where);
        }
        foreach ($class->getAttributes(Attr\UniqueConstraint::class) as $attribute) {
            $unique = $attribute->newInstance();
            $indexes[] = new IndexMapping($unique->fields, true, $unique->name, $unique->where);
        }

        return $indexes;
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return array<value-of<Attr\LifecycleEvent>, list<string>>
     */
    private function lifecycleCallbacks(\ReflectionClass $class): array
    {
        $enabled = null !== self::attribute($class, Attr\HasLifecycleCallbacks::class);
        $callbacks = [];

        foreach ($class->getMethods() as $method) {
            foreach ($method->getAttributes(LifecycleCallbackAttribute::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                if (!$enabled) {
                    throw MappingException::invalidMetadata($class->getName(), \sprintf('method %s() has a lifecycle callback attribute but the class lacks #[HasLifecycleCallbacks].', $method->getName()));
                }
                $callbacks[$attribute->newInstance()->getEvent()->value][] = $method->getName();
            }
        }

        return $callbacks;
    }

    /**
     * L'héritage d'entités est hors périmètre 1.0 : un parent ne peut pas porter de mapping.
     *
     * @param \ReflectionClass<object> $class
     */
    private function assertNoMappedParent(\ReflectionClass $class): void
    {
        for ($parent = $class->getParentClass(); false !== $parent; $parent = $parent->getParentClass()) {
            foreach ($parent->getProperties() as $property) {
                if ([] !== $property->getAttributes(MappingAttribute::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                    throw MappingException::invalidMetadata($class->getName(), \sprintf('parent class %s maps property $%s; entity inheritance is out of scope for 1.0.', $parent->getName(), $property->getName()));
                }
            }
        }
    }

    private static function defaultValue(string $class, string $field, mixed $default): string|int|float|bool|null
    {
        return match (true) {
            null === $default, \is_scalar($default) => $default,
            $default instanceof \BackedEnum => $default->value,
            default => throw MappingException::invalidField($class, $field, \sprintf('a column default must be a scalar or a backed enum case, got %s.', get_debug_type($default))),
        };
    }

    /**
     * @return class-string|null
     */
    private static function classString(string $class, ?string $repository): ?string
    {
        if (null !== $repository && !class_exists($repository)) {
            throw MappingException::invalidMetadata($class, \sprintf('repository class %s does not exist.', $repository));
        }

        return $repository;
    }

    /**
     * @template A of object
     *
     * @param \ReflectionClass<object>|\ReflectionProperty $reflector
     * @param class-string<A>                              $attribute
     *
     * @return A|null
     */
    private static function attribute(\ReflectionClass|\ReflectionProperty $reflector, string $attribute): ?object
    {
        $found = $reflector->getAttributes($attribute);

        return [] === $found ? null : $found[0]->newInstance();
    }
}
