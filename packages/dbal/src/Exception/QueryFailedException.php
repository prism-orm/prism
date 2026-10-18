<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * Requête refusée par le serveur. Porte le SQL (jamais les valeurs liées) et le SQLSTATE.
 *
 * @api
 */
final class QueryFailedException extends \RuntimeException implements DbalException
{
    private function __construct(
        string $message,
        public readonly string $sql,
        public readonly ?string $sqlState,
        \PDOException $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromPdo(\PDOException $previous, string $sql): self
    {
        $sqlState = \is_array($previous->errorInfo) && \is_string($previous->errorInfo[0] ?? null) ? $previous->errorInfo[0] : null;

        return new self(\sprintf('Query failed: %s (SQL: %s)', $previous->getMessage(), $sql), $sql, $sqlState, $previous);
    }
}
