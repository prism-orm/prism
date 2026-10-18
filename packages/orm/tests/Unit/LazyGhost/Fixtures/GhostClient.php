<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\LazyGhost\Fixtures;

/**
 * Entité finale avec identifiant readonly : le cas que les proxies Doctrine ne savent pas gérer.
 */
final class GhostClient
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public string $name,
    ) {
    }
}
