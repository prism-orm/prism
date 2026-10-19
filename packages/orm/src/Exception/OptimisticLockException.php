<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * La ligne a changé depuis sa lecture : l'UPDATE ou le DELETE conditionné par la version n'a
 * touché aucune ligne (RG-03.9), ou lock() optimiste sur une version périmée ou absente.
 *
 * @api
 */
final class OptimisticLockException extends \RuntimeException implements OrmException
{
    private function __construct(
        string $message,
        public readonly object $entity,
        public readonly mixed $expectedVersion,
    ) {
        parent::__construct($message);
    }

    public static function versionMismatch(object $entity, mixed $expectedVersion): self
    {
        return new self(\sprintf('The %s entity was modified or deleted by someone else: version %s is no longer current.', $entity::class, var_export($expectedVersion, true)), $entity, $expectedVersion);
    }

    public static function notVersioned(object $entity): self
    {
        return new self(\sprintf('Cannot lock the %s entity optimistically: it has no #[Version] field.', $entity::class), $entity, null);
    }
}
