<?php

declare(strict_types=1);

namespace Prism\Orm\Id;

use Prism\Dbal\Connection;

/**
 * Produit l'identifiant d'une entité, avant l'INSERT (séquence, UUID v7) ou après (IDENTITY).
 *
 * @internal
 */
interface IdGenerator
{
    /** Vrai si l'identifiant n'est connu qu'après l'INSERT. */
    public function isPostInsert(): bool;

    public function generate(Connection $connection): int|string;
}
