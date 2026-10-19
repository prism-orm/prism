<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Un orphelin (orphanRemoval) est rattaché à un autre parent dans le flush qui le supprime
 * (RG-04.5).
 *
 * @api
 */
final class OrphanReattachedException extends \LogicException implements OrmException
{
    private function __construct(string $message, public readonly object $entity)
    {
        parent::__construct($message);
    }

    /**
     * @param class-string $parent
     */
    public static function reattached(object $orphan, string $parent, string $field): self
    {
        return new self(\sprintf('The %s entity removed from %s::$%s (orphanRemoval) is deleted by this flush; it cannot be attached to another %s in the same flush. Flush the removal first, or move it without orphanRemoval (RG-04.5).', $orphan::class, $parent, $field, $parent), $orphan);
    }
}
