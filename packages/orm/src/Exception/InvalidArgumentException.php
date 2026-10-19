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
}
