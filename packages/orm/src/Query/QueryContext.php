<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Query\Expression;
use Prism\Dbal\Query\ExpressionBuilder;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\Association;
use Prism\Orm\Query\Metamodel\Field;
use Prism\Orm\Query\Metamodel\Metamodel;

/**
 * Compilation d'une requête typée : les alias de la requête et leur metadata. Résout un champ
 * en colonne citée et convertit ses valeurs par le Type du champ, dans les deux sens
 * (ADR 0009, ADR 0010, RG-05.4).
 *
 * @internal
 */
final class QueryContext
{
    /** @var array<string, array{Metamodel<object>, ClassMetadata<object>}> */
    private array $aliases = [];

    public function __construct(
        private readonly QueryBuilder $qb,
        private readonly MetadataFactory $metadata,
    ) {
    }

    /**
     * @param Metamodel<object> $metamodel
     *
     * @return ClassMetadata<object>
     */
    public function add(Metamodel $metamodel): ClassMetadata
    {
        $metadata = $this->metadata->getMetadataFor($metamodel->entity);
        $this->aliases[$metamodel->alias] = [$metamodel, $metadata];

        return $metadata;
    }

    /**
     * Les jointures SQL d'un metamodel joint, déduites de sa relation : une pour une relation
     * ToOne ou OneToMany, deux par la table pivot pour une ManyToMany (alias « o__tags »).
     *
     * @param Metamodel<object> $target
     *
     * @return array{AssociationMapping, list<array{string, string, string}>} relation, puis table, alias, condition
     *
     * @throws QueryException metamodel non joignable, alias absent de la requête
     */
    public function join(Metamodel $target): array
    {
        $relation = $target->via() ?? throw QueryException::notJoinable($target->alias, $target->entity);
        $owner = $relation->owner;
        $ownerMetadata = $this->owner($owner);
        $association = $ownerMetadata->getAssociation($relation->path);
        $targetMetadata = $this->add($target);
        $column = fn (string $alias, string $column): string => $this->qb->column($column, $alias);
        $ownerId = $column($owner->alias, $ownerMetadata->getIdField()->columnName);
        $targetId = $column($target->alias, $targetMetadata->getIdField()->columnName);

        if ($association->isOwningSide() && $association->isToOne()) {
            return [$association, [[$targetMetadata->tableName, $target->alias, $targetId . ' = ' . $column($owner->alias, (string) $association->joinColumn)]]];
        }
        if (AssociationType::ManyToMany !== $association->type) {
            $owning = $targetMetadata->getAssociation((string) $association->mappedBy);

            return [$association, [[$targetMetadata->tableName, $target->alias, $column($target->alias, (string) $owning->joinColumn) . ' = ' . $ownerId]]];
        }

        $joinTable = ($association->isOwningSide() ? $association : $targetMetadata->getAssociation((string) $association->mappedBy))->joinTable
            ?? throw new \LogicException('A ManyToMany owning side has a join table.');
        [$ownerColumn, $targetColumn] = $association->isOwningSide() ? [$joinTable->joinColumn, $joinTable->inverseJoinColumn] : [$joinTable->inverseJoinColumn, $joinTable->joinColumn];
        $pivot = $owner->alias . '__' . $relation->path;

        return [$association, [
            [$joinTable->name, $pivot, $column($pivot, $ownerColumn) . ' = ' . $ownerId],
            [$targetMetadata->tableName, $target->alias, $targetId . ' = ' . $column($pivot, $targetColumn)],
        ]];
    }

    /**
     * @param Metamodel<object> $metamodel
     *
     * @return ClassMetadata<object>
     *
     * @throws QueryException alias absent de la requête
     */
    public function metadataOf(Metamodel $metamodel): ClassMetadata
    {
        return $this->owner($metamodel);
    }

    public function expr(): ExpressionBuilder
    {
        return $this->qb->expr();
    }

    /**
     * Colonne citée « alias.colonne ».
     *
     * @throws QueryException alias absent de la requête, champ qui n'est plus mappé
     */
    public function column(Field $field): string
    {
        return $this->qb->column($this->mapping($field)->columnName, $field->owner->alias);
    }

    /**
     * Le nom du Type DBAL d'un champ.
     *
     * @throws QueryException
     */
    public function fieldType(Field $field): string
    {
        return $this->mapping($field)->type;
    }

    /**
     * Valeur PHP d'une colonne lue (projection) : convertie par le Type du champ, enum reconstruit.
     *
     * @throws QueryException
     */
    public function fieldValue(Field $field, mixed $value): mixed
    {
        $mapping = $this->mapping($field);

        return $this->owner($field->owner)->toFieldValue($mapping, $this->convert($mapping->type, $value));
    }

    /**
     * Valeur PHP d'une valeur lue, par un Type du DBAL.
     */
    public function convert(string $type, mixed $value): mixed
    {
        $connection = $this->qb->getConnection();

        return $connection->getTypeRegistry()->get($type)->toPhp($value, $connection->getConversionContext());
    }

    /**
     * @param 'eq'|'neq'|'lt'|'lte'|'gt'|'gte' $operator
     *
     * @throws QueryException
     */
    public function compare(Operand $operand, string $operator, mixed $value): Expression
    {
        $e = $this->expr();
        [$column, $value, $type] = [$operand->toSql($this), self::scalar($value), $operand->typeOf($this)];

        return match ($operator) {
            'eq' => $e->eq($column, $value, $type),
            'neq' => $e->neq($column, $value, $type),
            'lt' => $e->lt($column, $value, $type),
            'lte' => $e->lte($column, $value, $type),
            'gt' => $e->gt($column, $value, $type),
            'gte' => $e->gte($column, $value, $type),
        };
    }

    /**
     * @param list<mixed> $values
     *
     * @throws QueryException
     */
    public function within(Operand $operand, array $values, bool $negated): Expression
    {
        $values = array_map(self::scalar(...), $values);
        [$column, $type] = [$operand->toSql($this), $operand->typeOf($this)];

        return $negated ? $this->expr()->notIn($column, $values, $type) : $this->expr()->in($column, $values, $type);
    }

    /**
     * @throws QueryException
     */
    public function between(Operand $operand, mixed $low, mixed $high): Expression
    {
        return $this->expr()->between($operand->toSql($this), self::scalar($low), self::scalar($high), $operand->typeOf($this));
    }

    /**
     * Colonne de jointure d'une relation ToOne possédée, et le type de l'identifiant visé.
     *
     * @param Association<Metamodel<object>> $relation
     *
     * @return array{string, string}
     *
     * @throws QueryException
     */
    public function joinColumn(Association $relation): array
    {
        $metadata = $this->owner($relation->owner);
        if (!$metadata->hasAssociation($relation->path) || !($association = $metadata->getAssociation($relation->path))->isToOne() || !$association->isOwningSide()) {
            throw QueryException::notAForeignKey($metadata->className, $relation->path);
        }
        $target = $this->metadata->getMetadataFor($association->targetEntity);

        return [$this->qb->column((string) $association->joinColumn, $relation->owner->alias), $target->getIdField()->type];
    }

    /**
     * Identifiant d'une entité cible, ou la valeur telle quelle.
     *
     * @param Association<Metamodel<object>> $relation
     *
     * @throws QueryException entité sans identifiant
     */
    public function targetId(Association $relation, mixed $value): mixed
    {
        if (!\is_object($value)) {
            return $value;
        }
        $target = $this->metadata->getMetadataFor($value::class);
        $id = new \ReflectionProperty($target->className, $target->id->fieldName)->getValue($value);

        return $id ?? throw QueryException::unpersistedValue($value::class, $relation->path);
    }

    /**
     * @throws QueryException
     */
    private function mapping(Field $field): FieldMapping
    {
        $metadata = $this->owner($field->owner);
        if (!$metadata->hasField($field->path)) {
            throw QueryException::staleMetamodel($metadata->className, $field->path);
        }

        return $metadata->getField($field->path);
    }

    /**
     * @param Metamodel<object> $metamodel
     *
     * @return ClassMetadata<object>
     *
     * @throws QueryException
     */
    private function owner(Metamodel $metamodel): ClassMetadata
    {
        [$known, $metadata] = $this->aliases[$metamodel->alias] ?? [null, null];
        if ($known !== $metamodel || null === $metadata) {
            throw QueryException::unknownAlias($metamodel->alias, $metamodel->entity);
        }

        return $metadata;
    }

    private static function scalar(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
