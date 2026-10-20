<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Query\Expression;
use Prism\Dbal\Query\ExpressionBuilder;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Orm\Exception\QueryException;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\Association;
use Prism\Orm\Query\Metamodel\Field;
use Prism\Orm\Query\Metamodel\Metamodel;

/**
 * Compilation d'une requête typée : les alias de la requête et leur metadata. Résout un champ
 * en colonne citée et convertit ses valeurs par le Type du champ (ADR 0009, RG-05.4).
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
     * @param 'eq'|'neq'|'lt'|'lte'|'gt'|'gte' $operator
     *
     * @throws QueryException
     */
    public function compare(Field $field, string $operator, mixed $value): Expression
    {
        $e = $this->expr();
        [$column, $value, $type] = [$this->column($field), self::scalar($value), $this->mapping($field)->type];

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
    public function within(Field $field, array $values, bool $negated): Expression
    {
        $values = array_map(self::scalar(...), $values);
        $type = $this->mapping($field)->type;

        return $negated ? $this->expr()->notIn($this->column($field), $values, $type) : $this->expr()->in($this->column($field), $values, $type);
    }

    /**
     * @throws QueryException
     */
    public function between(Field $field, mixed $low, mixed $high): Expression
    {
        return $this->expr()->between($this->column($field), self::scalar($low), self::scalar($high), $this->mapping($field)->type);
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
