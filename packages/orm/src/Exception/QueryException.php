<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Requête typée invalide : alias absent de la requête, metamodel périmé, condition impossible.
 *
 * @api
 */
final class QueryException extends \LogicException implements OrmException
{
    /**
     * @param class-string $entity
     */
    public static function unknownAlias(string $alias, string $entity): self
    {
        return new self(\sprintf('The alias "%s" (%s) is not part of this query: start the query from it, or join it.', $alias, $entity));
    }

    /**
     * @param class-string $entity
     */
    public static function staleMetamodel(string $entity, string $field): self
    {
        return new self(\sprintf('%s::$%s is not mapped anymore: the metamodel is stale, regenerate it (db:metamodel:generate).', $entity, $field));
    }

    /**
     * @param class-string $entity
     */
    public static function notAForeignKey(string $entity, string $field): self
    {
        return new self(\sprintf('%s::$%s is not an owning ToOne relation: only a foreign key can be compared; join the relation instead.', $entity, $field));
    }

    /**
     * @param class-string $entity
     */
    public static function unpersistedValue(string $entity, string $field): self
    {
        return new self(\sprintf('The condition on $%s uses a %s that has no identifier yet; flush it first.', $field, $entity));
    }

    public static function negative(string $clause, int $value): self
    {
        return new self(\sprintf('%s must be zero or positive, got %d.', $clause, $value));
    }
}
