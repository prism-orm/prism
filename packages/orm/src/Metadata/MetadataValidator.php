<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\Cascade;
use Prism\Orm\Mapping\Entity;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Mapping\LifecycleEvent;
use Prism\Orm\Mapping\OnDelete;

/**
 * Vérifications qui portent sur l'ensemble des entités et sur le registre des types :
 * colonnes en double, cohérence des deux côtés d'une relation (RG-02.8), types connus,
 * stratégies d'identifiant (RG-02.4). Toutes les erreurs sont rapportées en une fois.
 *
 * @api
 */
final class MetadataValidator
{
    /** limite la plus courte des plateformes supportées (PostgreSQL tronquerait sans erreur) */
    private const int MAX_IDENTIFIER_LENGTH = 63;

    private const array INTEGER_TYPES = ['integer', 'smallint', 'bigint'];

    private const array VERSION_TYPES = ['integer', 'smallint', 'bigint', 'datetime_immutable'];

    private const array LENGTH_TYPES = ['string', 'binary'];

    private const array NO_DEFAULT_TYPES = ['text', 'json', 'binary'];

    /** @var list<MappingError> */
    private array $errors = [];

    private readonly TypeRegistry $types;

    /**
     * @param TypeRegistry|null $types types connus ; les 14 types natifs du DBAL par défaut
     */
    public function __construct(?TypeRegistry $types = null)
    {
        $this->types = $types ?? TypeRegistry::withDefaults();
    }

    /**
     * @param list<ClassMetadata<object>> $metadata
     *
     * @return list<MappingError>
     */
    public function validate(array $metadata): array
    {
        $this->errors = [];
        $byClass = [];
        foreach ($metadata as $class) {
            $byClass[$class->className] = $class;
        }

        $this->validateTables($metadata);
        foreach ($metadata as $class) {
            $this->validateColumns($class);
            $this->validateFields($class);
            $this->validateIdentifierAndVersion($class);
            $this->validateAssociations($class, $byClass);
            $this->validateIndexes($class);
            $this->validateCallbacks($class);
            $this->validateTenancy($class);
            $this->validateBehaviors($class);
        }

        return $this->errors;
    }

    /**
     * @param list<ClassMetadata<object>> $metadata
     *
     * @throws MappingException toutes les erreurs trouvées
     */
    public function assertValid(array $metadata): void
    {
        // les avertissements ne bloquent pas
        $errors = array_values(array_filter($this->validate($metadata), static fn (MappingError $e): bool => MappingSeverity::Error === $e->severity));
        if ([] !== $errors) {
            throw MappingException::invalidMapping($errors);
        }
    }

    /**
     * @param list<ClassMetadata<object>> $metadata
     */
    private function validateTables(array $metadata): void
    {
        $entityTables = [];
        foreach ($metadata as $class) {
            $key = strtolower($class->tableName);
            if (isset($entityTables[$key])) {
                $this->error($class->className, null, \sprintf('table "%s" is already used by %s.', $class->tableName, $entityTables[$key]));
            }
            $entityTables[$key] = $class->className;
            $this->assertLength($class->className, null, 'table', $class->tableName);
        }

        foreach ($metadata as $class) {
            foreach ($class->getAssociations() as $association) {
                if (null === $association->joinTable) {
                    continue;
                }
                $this->assertLength($class->className, $association->fieldName, 'join table', $association->joinTable->name);
                if (isset($entityTables[strtolower($association->joinTable->name)])) {
                    $this->error($class->className, $association->fieldName, \sprintf('join table "%s" has the same name as the table of %s.', $association->joinTable->name, $entityTables[strtolower($association->joinTable->name)]));
                }
            }
        }
    }

    /**
     * @param ClassMetadata<object> $class
     */
    private function validateColumns(ClassMetadata $class): void
    {
        $owners = [];
        $register = function (string $column, string $owner) use ($class, &$owners): void {
            $this->assertLength($class->className, $owner, 'column', $column);
            $key = strtolower($column);
            if (isset($owners[$key])) {
                $this->error($class->className, $owner, \sprintf('column "%s" is already used by $%s.', $column, $owners[$key]));

                return;
            }
            $owners[$key] = $owner;
        };

        foreach ($class->getFields() as $field) {
            $register($field->columnName, $field->fieldName);
        }
        foreach ($class->getEmbedded() as $embedded) {
            foreach ($embedded->getFields() as $field) {
                $register($field->columnName, $embedded->fieldName . '.' . $field->fieldName);
            }
        }
        foreach ($class->getAssociations() as $association) {
            if ($association->isOwningSide() && null !== $association->joinColumn) {
                $register($association->joinColumn, $association->fieldName);
            }
        }
        // colonne du tenant que ne porte aucune propriété (ADR 0014)
        $tenancy = $class->tenancy;
        if (null !== $tenancy && null === $tenancy->field) {
            $this->assertLength($class->className, null, 'column', $tenancy->column);
            if (isset($owners[strtolower($tenancy->column)])) {
                $this->error($class->className, null, \sprintf('the #[Tenanted] column "%s" is already used by $%s; rename one of them (#[Tenanted(column: ...)]).', $tenancy->column, $owners[strtolower($tenancy->column)]));
            }
        }
    }

    /**
     * ADR 0015 : les champs que les comportements écrivent ou lisent existent et ont le bon type.
     *
     * @param ClassMetadata<object> $class
     */
    private function validateBehaviors(ClassMetadata $class): void
    {
        $behaviors = $class->behaviors;
        $expect = function (?string $field, string $attribute, string $type, ?bool $nullable) use ($class): void {
            if (null === $field) {
                return;
            }
            if (!$class->hasField($field) || str_contains($field, '.')) {
                $this->error($class->className, null, \sprintf('%s names "%s", which is not a mapped field.', $attribute, $field));

                return;
            }
            $mapping = $class->getField($field);
            if ($mapping->type !== $type) {
                $this->error($class->className, $field, \sprintf('%s needs a "%s" field, not "%s".', $attribute, $type, $mapping->type));
            } elseif (null !== $nullable && $mapping->nullable !== $nullable) {
                $this->error($class->className, $field, \sprintf('%s needs a %s field.', $attribute, $nullable ? 'nullable' : 'non-nullable'));
            }
        };
        $expect($behaviors->createdAt, '#[Timestampable]', 'datetime_immutable', null);
        $expect($behaviors->updatedAt, '#[Timestampable]', 'datetime_immutable', null);
        // sans acteur (commande, tâche), l'auteur reste NULL
        $expect($behaviors->createdBy, '#[Blameable]', 'string', true);
        $expect($behaviors->updatedBy, '#[Blameable]', 'string', true);
        $expect($behaviors->deletedAt, '#[SoftDeletable]', 'datetime_immutable', true);
        foreach ($behaviors->auditExclude as $field) {
            if (!$class->hasField($field) && !$class->hasAssociation($field)) {
                $this->error($class->className, null, \sprintf('#[Auditable] excludes "%s", which is not a mapped field or relation.', $field));
            }
        }
    }

    /**
     * RG-08.1 : type de l'identifiant du tenant, et propriété qui porte sa colonne (ADR 0014).
     *
     * @param ClassMetadata<object> $class
     */
    private function validateTenancy(ClassMetadata $class): void
    {
        $tenancy = $class->tenancy;
        if (null === $tenancy) {
            return;
        }
        if (!\in_array($tenancy->type, TenancyMapping::TYPES, true)) {
            $this->error($class->className, null, \sprintf('the tenant type "%s" is not supported; use one of: %s.', $tenancy->type, implode(', ', TenancyMapping::TYPES)));
        }
        if (null === $tenancy->field) {
            return;
        }
        $field = $class->getField($tenancy->field);
        if ($tenancy->field === $class->id->fieldName) {
            $this->error($class->className, $field->fieldName, \sprintf('the identifier cannot be the #[Tenanted] column "%s".', $tenancy->column));
        }
        if ($field->type !== $tenancy->type) {
            $this->error($class->className, $field->fieldName, \sprintf('the #[Tenanted] column "%s" has type "%s", but the tenant type is "%s".', $tenancy->column, $field->type, $tenancy->type));
        }
        if ($field->nullable !== $tenancy->global) {
            $this->error($class->className, $field->fieldName, $tenancy->global
                ? \sprintf('the #[Tenanted(global: true)] column "%s" holds NULL for shared rows; make the property nullable.', $tenancy->column)
                : \sprintf('the #[Tenanted] column "%s" is never NULL; make the property non-nullable, or use #[Tenanted(global: true)] for shared rows.', $tenancy->column));
        }
    }

    /**
     * @param ClassMetadata<object> $class
     */
    private function validateFields(ClassMetadata $class): void
    {
        $fields = array_map(static fn (FieldMapping $f): array => [$f->fieldName, $f], $class->getFields());
        foreach ($class->getEmbedded() as $embedded) {
            foreach ($embedded->getFields() as $field) {
                $fields[] = [$embedded->fieldName . '.' . $field->fieldName, $field];
            }
        }

        foreach ($fields as [$path, $field]) {
            $this->validateField($class->className, $path, $field);
        }
    }

    /**
     * @param class-string $class
     */
    private function validateField(string $class, string $path, FieldMapping $field): void
    {
        if (!$this->types->has($field->type)) {
            $this->error($class, $path, \sprintf('unknown type "%s"; registered types: %s. Register custom types with TypeRegistry::register().', $field->type, implode(', ', $this->types->getNames())));

            return;
        }

        if (null !== $field->enumType) {
            $backing = (string) new \ReflectionEnum($field->enumType)->getBackingType();
            $expected = 'int' === $backing ? self::INTEGER_TYPES : ['string', 'text'];
            if (!\in_array($field->type, $expected, true)) {
                $this->error($class, $path, \sprintf('enum %s is backed by %s and cannot be stored in a "%s" column.', $field->enumType, $backing, $field->type));
            }
        }

        if ('decimal' === $field->type && (null === $field->precision || null === $field->scale)) {
            $this->error($class, $path, 'a decimal column needs a precision and a scale, for example #[Column(type: \'decimal\', precision: 12, scale: 2)].');
        }
        if (null !== $field->length && !\in_array($field->type, self::LENGTH_TYPES, true)) {
            $this->error($class, $path, \sprintf('length is not supported by the "%s" type.', $field->type));
        }
        if ($field->fixed && (!\in_array($field->type, self::LENGTH_TYPES, true) || null === $field->length)) {
            $this->error($class, $path, \sprintf('fixed needs a "string" or "binary" column with a length, for example #[Column(length: 3, fixed: true)]; got "%s"%s.', $field->type, null === $field->length ? ' without length' : ''));
        }
        if ('decimal' !== $field->type && (null !== $field->precision || null !== $field->scale)) {
            $this->error($class, $path, \sprintf('precision and scale are only supported by the "decimal" type, not "%s".', $field->type));
        }

        if (null !== $field->default) {
            if (\in_array($field->type, self::NO_DEFAULT_TYPES, true)) {
                $this->error($class, $path, \sprintf('a "%s" column cannot have a default value (MySQL refuses it).', $field->type));
            } elseif (!self::defaultMatchesType($field->type, $field->default)) {
                $this->error($class, $path, \sprintf('the default value %s does not match the "%s" type.', var_export($field->default, true), $field->type));
            }
        }
    }

    /**
     * @param ClassMetadata<object> $class
     */
    private function validateIdentifierAndVersion(ClassMetadata $class): void
    {
        $id = $class->getIdField();
        $strategy = $class->id->strategy;

        if (GenerationStrategy::UuidV7 === $strategy && 'uuid' !== $id->type) {
            $this->error($class->className, $id->fieldName, \sprintf('GenerationStrategy::UuidV7 needs a "uuid" column, not "%s".', $id->type));
        }
        if (null !== $strategy && GenerationStrategy::UuidV7 !== $strategy && !\in_array($id->type, self::INTEGER_TYPES, true)) {
            $this->error($class->className, $id->fieldName, \sprintf('GenerationStrategy::%s needs an integer, smallint or bigint column, not "%s"; use UuidV7 for a uuid.', $strategy->name, $id->type));
        }

        if (null !== $class->versionField) {
            $version = $class->getField($class->versionField);
            if (!\in_array($version->type, self::VERSION_TYPES, true)) {
                $this->error($class->className, $version->fieldName, \sprintf('a #[Version] field must be an integer, smallint, bigint or datetime_immutable column, not "%s".', $version->type));
            }
            if ($version->nullable) {
                $this->error($class->className, $version->fieldName, 'a #[Version] field cannot be nullable.');
            }
        }
    }

    /**
     * @param ClassMetadata<object>                      $class
     * @param array<class-string, ClassMetadata<object>> $byClass
     */
    private function validateAssociations(ClassMetadata $class, array $byClass): void
    {
        foreach ($class->getAssociations() as $association) {
            $field = $association->fieldName;

            if ($association->isCollection() && !self::isTypedCollection($class->className, $field)) {
                $this->error($class->className, $field, \sprintf('a collection relation must be typed %s (not array, iterable or nullable), initialized with new ArrayCollection() in the constructor (ADR 0007).', Collection::class));
            }

            if (OnDelete::SetNull === $association->onDelete && $association->isToOne() && $association->isOwningSide() && !$association->nullable) {
                $this->error($class->className, $field, 'onDelete: SetNull needs a nullable relation; make the property nullable or choose another action.');
            }

            $target = $byClass[$association->targetEntity] ?? null;
            if (null === $target) {
                $isEntity = [] !== new \ReflectionClass($association->targetEntity)->getAttributes(Entity::class);
                $this->error($class->className, $field, $isEntity
                    ? \sprintf('the target %s is not among the mapped entities; add its directory to the metadata sources.', $association->targetEntity)
                    : \sprintf('the target %s is not an entity.', $association->targetEntity));
                continue;
            }

            foreach (array_keys($association->orderBy) as $orderField) {
                if (!$target->hasField($orderField)) {
                    $this->error($class->className, $field, \sprintf('orderBy uses "%s", which is not a field of %s.', $orderField, $target->className));
                }
            }
            if (null !== $association->indexBy && !$target->hasField($association->indexBy)) {
                $this->error($class->className, $field, \sprintf('indexBy uses "%s", which is not a field of %s.', $association->indexBy, $target->className));
            }

            $this->validateOtherSide($class, $association, $target);
            $this->validateRemovalPolicy($class, $association, $target);
        }
    }

    /**
     * RG-02.8 : mappedBy / inversedBy désignent une relation de la cible qui revient vers cette
     * entité, du type correspondant, et qui pointe en retour sur ce champ.
     *
     * @param ClassMetadata<object> $class
     * @param ClassMetadata<object> $target
     */
    private function validateOtherSide(ClassMetadata $class, AssociationMapping $association, ClassMetadata $target): void
    {
        $other = $association->mappedBy ?? $association->inversedBy;
        if (null === $other) {
            return;
        }
        $keyword = null !== $association->mappedBy ? 'mappedBy' : 'inversedBy';
        $field = $association->fieldName;

        if (!$target->hasAssociation($other)) {
            $this->error($class->className, $field, \sprintf('%s "%s" is not a relation of %s.', $keyword, $other, $target->className));

            return;
        }
        $back = $target->getAssociation($other);

        if ($back->targetEntity !== $class->className) {
            $this->error($class->className, $field, \sprintf('%s "%s" targets %s, not %s.', $keyword, $other, $back->targetEntity, $class->className));

            return;
        }

        $expected = match ($association->type) {
            AssociationType::OneToMany => AssociationType::ManyToOne,
            AssociationType::ManyToOne => AssociationType::OneToMany,
            AssociationType::OneToOne => AssociationType::OneToOne,
            AssociationType::ManyToMany => AssociationType::ManyToMany,
        };
        if ($back->type !== $expected) {
            $this->error($class->className, $field, \sprintf('a %s cannot be paired with the %s %s::$%s; expected a %s.', $association->type->name, $back->type->name, $target->className, $other, $expected->name));

            return;
        }

        $pointsBack = null !== $association->mappedBy ? $back->inversedBy : $back->mappedBy;
        if ($pointsBack !== $field) {
            $this->error($class->className, $field, \sprintf(
                '%s "%s" is not paired back: %s::$%s must declare %s: \'%s\'.',
                $keyword,
                $other,
                $target->className,
                $other,
                null !== $association->mappedBy ? 'inversedBy' : 'mappedBy',
                $field,
            ));
        }
    }

    /**
     * @param ClassMetadata<object> $class
     */
    private function validateIndexes(ClassMetadata $class): void
    {
        foreach ($class->indexes as $index) {
            foreach ($index->fields as $field) {
                $isColumn = $class->hasField($field)
                    || ($class->hasAssociation($field) && $class->getAssociation($field)->isOwningSide() && $class->getAssociation($field)->isToOne());
                if (!$isColumn) {
                    $this->error($class->className, null, \sprintf('%s on "%s": "%s" is not a mapped field or an owning to-one relation.', $index->unique ? '#[UniqueConstraint]' : '#[Index]', implode(', ', $index->fields), $field));
                }
            }
        }
    }

    /**
     * Callbacks et méthodes de listener : publics, non statiques, et des paramètres qui
     * acceptent ce que l'ORM passe (l'événement ; pour un listener, l'entité puis l'événement).
     *
     * @param ClassMetadata<object> $class
     */
    private function validateCallbacks(ClassMetadata $class): void
    {
        foreach (LifecycleEvent::cases() as $event) {
            foreach ($class->getLifecycleCallbacks($event) as $method) {
                $this->validateCallback($class->className, $class->className, $method, $event);
            }
        }

        foreach ($class->entityListeners as $listener) {
            if (!class_exists($listener)) {
                $this->error($class->className, null, \sprintf('entity listener %s does not exist or is not a class.', $listener));
                continue;
            }
            $events = $class->entityListenerCallbacks[$listener] ?? [];
            if ([] === $events) {
                $this->error($class->className, null, \sprintf('entity listener %s has no method with a lifecycle attribute (#[PrePersist], #[PostLoad]…).', $listener));
            }
            foreach ($events as $event => $methods) {
                foreach ($methods as $method) {
                    $this->validateCallback($class->className, $listener, $method, LifecycleEvent::from($event));
                }
            }
        }
    }

    /**
     * @param class-string $entity
     * @param class-string $owner  l'entité (callback) ou la classe de listener
     */
    private function validateCallback(string $entity, string $owner, string $method, LifecycleEvent $event): void
    {
        $listener = $owner !== $entity;
        $reflection = new \ReflectionMethod($owner, $method);
        $label = $listener ? \sprintf('entity listener method %s::%s()', $owner, $method) : \sprintf('lifecycle callback %s()', $method);

        if (!$reflection->isPublic() || $reflection->isStatic()) {
            $this->error($entity, null, \sprintf('%s must be a public, non-static method.', $label));

            return;
        }
        $expected = $listener ? [$entity, $event->eventClass()] : [$event->eventClass()];
        $parameters = $reflection->getParameters();
        if (\count($parameters) > \count($expected)) {
            $this->error($entity, null, \sprintf('%s takes at most %s.', $label, $listener ? 'two arguments: the entity and the event' : 'one argument: the event'));

            return;
        }
        foreach ($parameters as $i => $parameter) {
            if (!self::accepts($parameter->getType(), $expected[$i])) {
                $this->error($entity, null, \sprintf('%s: parameter $%s must accept %s, which #[%s] passes.', $label, $parameter->getName(), $expected[$i], ucfirst($event->value)));
            }
        }
    }

    /**
     * ADR 0007 : l'ORM suit l'instance de la collection, jamais un tableau copié.
     *
     * @param class-string $class
     */
    private static function isTypedCollection(string $class, string $field): bool
    {
        if (!property_exists($class, $field)) {
            return false;
        }
        $type = new \ReflectionProperty($class, $field)->getType();

        return $type instanceof \ReflectionNamedType && Collection::class === $type->getName() && !$type->allowsNull();
    }

    // un paramètre sans type, object, mixed, ou une classe parente de celle passée
    private static function accepts(?\ReflectionType $type, string $class): bool
    {
        if (null === $type) {
            return true;
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::accepts($member, $class)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (!self::accepts($member, $class)) {
                    return false;
                }
            }

            return true;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        return \in_array($type->getName(), ['object', 'mixed'], true) || (!$type->isBuiltin() && is_a($class, $type->getName(), true));
    }

    /**
     * @param class-string $class
     */
    private function assertLength(string $class, ?string $field, string $what, string $name): void
    {
        if (\strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
            $this->error($class, $field, \sprintf('%s name "%s" is %d bytes long; the maximum is %d (PostgreSQL would silently truncate it).', $what, $name, \strlen($name), self::MAX_IDENTIFIER_LENGTH));
        }
    }

    private static function defaultMatchesType(string $type, string|int|float|bool $default): bool
    {
        return match ($type) {
            'integer', 'smallint', 'bigint' => \is_int($default),
            'boolean' => \is_bool($default),
            'float' => \is_int($default) || \is_float($default),
            'decimal' => \is_int($default) || (\is_string($default) && 1 === preg_match('/^[+-]?\d+(\.\d+)?$/', $default)),
            default => \is_string($default),
        };
    }

    /**
     * @param class-string $class
     */
    private function error(string $class, ?string $field, string $message): void
    {
        $this->errors[] = new MappingError($class, $field, $message);
    }

    /**
     * @param class-string $class
     */
    private function warning(string $class, string $field, string $message): void
    {
        $this->errors[] = new MappingError($class, $field, $message, MappingSeverity::Warning);
    }

    /**
     * RG-04.6 : la suppression des enfants d'un OneToMany réglée deux fois, ou de deux façons
     * contraires, par l'ORM (cascade: remove, orphanRemoval) et par la base (onDelete).
     *
     * @param ClassMetadata<object> $class
     * @param ClassMetadata<object> $target
     */
    private function validateRemovalPolicy(ClassMetadata $class, AssociationMapping $association, ClassMetadata $target): void
    {
        if (AssociationType::OneToMany !== $association->type || !$target->hasAssociation((string) $association->mappedBy)) {
            return;
        }
        $owning = $target->getAssociation((string) $association->mappedBy);
        $other = $target->className . '::$' . $owning->fieldName;

        if (OnDelete::Cascade === $owning->onDelete && ($association->orphanRemoval || \in_array(Cascade::Remove, $association->cascade, true))) {
            $this->warning($class->className, $association->fieldName, \sprintf('cascade: remove repeats onDelete: CASCADE of %s: the database already deletes these rows, without events. Keep one of them (RG-04.6).', $other));
        }
        if (OnDelete::SetNull === $owning->onDelete && $association->orphanRemoval) {
            $this->warning($class->className, $association->fieldName, \sprintf('orphanRemoval contradicts onDelete: SET NULL of %s: the ORM deletes the rows the database would keep. Keep one of them (RG-04.6).', $other));
        }
    }
}
