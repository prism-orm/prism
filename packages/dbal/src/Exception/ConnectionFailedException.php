<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * Échec d'ouverture de la connexion PDO.
 *
 * @api
 */
final class ConnectionFailedException extends \RuntimeException implements DbalException
{
    public static function fromPdo(\PDOException $previous, string $target): self
    {
        return new self(\sprintf('Could not connect to %s: %s', $target, $previous->getMessage()), 0, $previous);
    }
}
