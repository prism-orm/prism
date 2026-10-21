<?php

declare(strict_types=1);

namespace Prism\Migrations;

/**
 * Où vivent les migrations, et dans quel environnement elles tournent : en prod, migrate et
 * execute demandent confirmation (RG-06.9).
 *
 * @api
 */
final class MigrationsConfiguration
{
    public function __construct(
        public readonly string $directory = 'migrations',
        public readonly string $namespace = 'App\\Migrations',
        public readonly string $environment = 'dev',
    ) {
    }

    public function finder(): MigrationFinder
    {
        return new MigrationFinder($this->directory, $this->namespace);
    }

    public function isProduction(): bool
    {
        return 'prod' === $this->environment;
    }
}
