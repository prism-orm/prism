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

    /**
     * @param class-string $entity
     */
    public static function notJoinable(string $alias, string $entity): self
    {
        return new self(\sprintf('The alias "%s" (%s) is not a relation: join a metamodel obtained from one, such as $o->client->as(\'c\').', $alias, $entity));
    }

    public static function duplicateAlias(string $alias): self
    {
        return new self(\sprintf('The alias "%s" is already used in this query: give each join its own alias.', $alias));
    }

    public static function joinedFromUnknownAlias(string $alias, string $owner): self
    {
        return new self(\sprintf('The alias "%s" is joined from "%s", which is not part of this query yet: join "%s" first.', $alias, $owner, $owner));
    }

    public static function fetchJoinOfUnfetchedAlias(string $alias, string $owner): self
    {
        return new self(\sprintf('The alias "%s" is fetch-joined from "%s", which is not fetched: fetch-join "%s" too, or join "%s" without fetching.', $alias, $owner, $owner, $alias));
    }

    public static function limitWithCollectionFetchJoin(string $alias): self
    {
        return new self(\sprintf('A limit or an offset would cut the collection fetch-joined as "%s" and leave it partly loaded; use the Paginator, or join it without fetching.', $alias));
    }
}
