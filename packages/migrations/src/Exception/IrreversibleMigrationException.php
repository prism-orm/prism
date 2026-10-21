<?php

declare(strict_types=1);

namespace Prism\Migrations\Exception;

/**
 * RG-06.7 : une migration sans down(), ou dont l'inverse détruirait des données.
 *
 * @api
 */
final class IrreversibleMigrationException extends \RuntimeException implements MigrationsException
{
    public static function forVersion(string $version, string $reason = 'it has no down()'): self
    {
        return new self(\sprintf('The migration %s cannot be reverted: %s.', $version, $reason));
    }
}
