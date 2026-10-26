<?php

declare(strict_types=1);

namespace Prism\Orm\Audit;

/**
 * L'identifiant de la requête en cours, pour relier les lignes d'audit d'une même requête
 * (RG-08.10, ADR 0015) ; null hors requête.
 *
 * @api
 */
interface RequestIdProvider
{
    public function getRequestId(): ?string;
}
