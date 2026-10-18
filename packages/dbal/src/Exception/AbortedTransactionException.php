<?php

declare(strict_types=1);

namespace Prism\Dbal\Exception;

/**
 * PostgreSQL : une requête en échec a invalidé la transaction. Un COMMIT serait silencieusement
 * transformé en ROLLBACK par le serveur ; Prism annule la transaction et le signale (RG-01.2).
 *
 * @api
 */
final class AbortedTransactionException extends \RuntimeException implements DbalException
{
    public static function onCommit(): self
    {
        return new self('Cannot commit: a failed statement aborted the transaction and the server would roll it back. The transaction has been rolled back; roll back to the enclosing savepoint to recover from a failed statement.');
    }
}
