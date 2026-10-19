<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * commit() ou rollBack() sans transaction active (RG-01.2).
 *
 * @api
 */
final class NoActiveTransactionException extends \LogicException implements DbalException
{
    public static function forOperation(string $operation): self
    {
        return new self(\sprintf('Cannot %s: no transaction is active. Call beginTransaction() first.', $operation));
    }

    public static function implicitlyCommitted(): self
    {
        return new self('Cannot roll back: the server already committed the transaction implicitly (a DDL statement on MySQL or MariaDB commits the current transaction).');
    }
}
