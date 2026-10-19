<?php

declare(strict_types=1);

namespace Prism\Orm\Persister;

use Prism\Dbal\Connection;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Persisters sur une Connection du DBAL ; la transaction du flush est Connection::transactional()
 * (un savepoint si une transaction est déjà ouverte, RG-01.1).
 *
 * @internal
 */
final class DbalPersisterProvider implements PersisterProvider
{
    /** @var array<class-string, BasicEntityPersister> */
    private array $persisters = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function getPersister(ClassMetadata $metadata): BasicEntityPersister
    {
        return $this->persisters[$metadata->className] ??= new BasicEntityPersister($this->connection, $metadata);
    }

    public function transactional(\Closure $operations): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operations());
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
