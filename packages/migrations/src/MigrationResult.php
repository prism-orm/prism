<?php

declare(strict_types=1);

namespace Prism\Migrations;

/**
 * @internal
 */
final class MigrationResult
{
    /**
     * @param list<ExecutedMigration> $migrations dans l'ordre d'exécution
     * @param list<string>            $warnings
     */
    public function __construct(
        public readonly array $migrations,
        public readonly array $warnings,
        public readonly bool $dryRun,
    ) {
    }
}
