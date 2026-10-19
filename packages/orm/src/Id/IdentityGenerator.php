<?php

declare(strict_types=1);

namespace Prism\Orm\Id;

use Prism\Dbal\Connection;

/**
 * IDENTITY / AUTO_INCREMENT : la base attribue l'identifiant à l'INSERT.
 *
 * @internal
 */
final class IdentityGenerator implements IdGenerator
{
    public function isPostInsert(): bool
    {
        return true;
    }

    public function generate(Connection $connection): string
    {
        return $connection->lastInsertId();
    }
}
