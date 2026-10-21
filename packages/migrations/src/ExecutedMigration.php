<?php

declare(strict_types=1);

namespace Prism\Migrations;

/**
 * Une migration exécutée (ou simulée par --dry-run), avec le SQL de la plateforme.
 *
 * @api
 */
final class ExecutedMigration
{
    /**
     * @param list<string> $sql
     */
    public function __construct(
        public readonly string $version,
        public readonly Direction $direction,
        public readonly array $sql,
        public readonly bool $transactional,
        public readonly ?int $executionTimeMs = null,
    ) {
    }
}
