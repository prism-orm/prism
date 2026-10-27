<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Argument refusé par l'API de l'ORM.
 *
 * @api
 */
final class InvalidArgumentException extends \InvalidArgumentException implements OrmException
{
    public static function batchSize(int $batchSize): self
    {
        return new self(\sprintf('The batch size must be at least 1, got %d.', $batchSize));
    }

    public static function unknownCommitNode(int $id): self
    {
        return new self(\sprintf('Unknown entity %d in the commit order: add it with addNode() first.', $id));
    }

    /**
     * @param class-string $class
     */
    public static function unpersistedCriteria(string $class, string $field): self
    {
        return new self(\sprintf('The criteria on %s::$%s use an entity that has no identifier yet; flush it first.', $class, $field));
    }

    public static function threshold(int $threshold): self
    {
        return new self(\sprintf('The N+1 threshold must be at least 1, got %d.', $threshold));
    }

    /**
     * @param class-string $class
     */
    public static function notSoftDeletable(string $class): self
    {
        return new self(\sprintf('Cannot restore a %s entity: it is not #[SoftDeletable].', $class));
    }

    public static function duplicateFilter(string $name): self
    {
        return new self(\sprintf('A filter named "%s" is already registered.', $name));
    }

    /**
     * @param list<string> $known
     */
    public static function unknownFilter(string $name, array $known): self
    {
        return new self(\sprintf('Unknown filter "%s"; registered filters: %s.', $name, [] === $known ? 'none' : implode(', ', $known)));
    }

    public static function invalidVector(string $reason): self
    {
        return new self(\sprintf('Invalid vector: %s.', $reason));
    }

    public static function lockedFilter(string $name): self
    {
        return new self(\sprintf('The "%s" filter cannot be disabled; use TenantContext::runAsSystem() for an explicit cross-tenant operation (ADR 0014).', $name));
    }
}
