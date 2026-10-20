<?php

declare(strict_types=1);

namespace Prism\Dbal;

/**
 * Voit chaque requête paramétrée réussie d'une Connection (ADR 0008) : détecteur N+1, profiler.
 * Ni les requêtes de transaction ou de session, ni les requêtes en échec.
 *
 * @api
 */
interface QueryObserver
{
    /**
     * @param array<int|string, mixed> $params valeurs liées, par nom ou position
     */
    public function queryExecuted(string $sql, array $params, float $durationMs): void;
}
