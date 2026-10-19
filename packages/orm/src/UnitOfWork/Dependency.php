<?php

declare(strict_types=1);

namespace Prism\Orm\UnitOfWork;

/**
 * FK d'une entité à insérer vers une autre : $to est inséré avant $from.
 *
 * @internal
 */
final class Dependency
{
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly string $field,
        public readonly bool $nullable,
    ) {
    }
}
