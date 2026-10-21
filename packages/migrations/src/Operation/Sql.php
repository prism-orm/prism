<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

/**
 * SQL brut, avec ses paramètres liés : l'échappatoire des migrations (ADR 0012). Ni portable ni
 * analysable ; le linter signale chaque appel (RG-06.5).
 *
 * @internal créée par MigrationBuilder
 */
final class Sql implements Operation
{
    /**
     * @param array<int<0, max>|string, mixed> $params
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'sql', 'sql' => $this->sql, 'params' => $this->params];
    }
}
