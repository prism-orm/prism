<?php

declare(strict_types=1);

namespace Prism\Migrations;

/**
 * L'état d'une migration, pour db:migrations:status.
 *
 * @api
 */
final class MigrationStatus
{
    public function __construct(
        public readonly string $version,
        public readonly MigrationState $state,
        public readonly string $description = '',
        public readonly ?\DateTimeImmutable $executedAt = null,
        public readonly ?int $executionTimeMs = null,
    ) {
    }
}
