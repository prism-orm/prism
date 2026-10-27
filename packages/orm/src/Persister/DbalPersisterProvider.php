<?php

declare(strict_types=1);

namespace Prism\Orm\Persister;

use Prism\Dbal\Connection;
use Prism\Orm\Filter\FilterCollection;
use Prism\Orm\Id\IdGeneratorFactory;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;

/**
 * Persisters sur une Connection du DBAL ; la transaction du flush est Connection::transactional()
 * (un savepoint si une transaction est déjà ouverte, RG-01.1). Les filtres sont ceux de
 * l'EntityManager (ADR 0014).
 *
 * @internal
 */
final class DbalPersisterProvider implements PersisterProvider
{
    /** @var array<class-string, BasicEntityPersister> */
    private array $persisters = [];

    private readonly IdGeneratorFactory $generators;

    private readonly MetadataFactory $metadata;

    // la MetadataFactory type les colonnes de jointure comme l'identifiant de leur cible
    public function __construct(
        private readonly Connection $connection,
        ?MetadataFactory $metadata = null,
        private readonly FilterCollection $filters = new FilterCollection(),
    ) {
        $this->generators = new IdGeneratorFactory($connection->getPlatform());
        $this->metadata = $metadata ?? new MetadataFactory(new AttributeDriver(), types: $connection->getTypeRegistry());
    }

    public function getPersister(ClassMetadata $metadata): BasicEntityPersister
    {
        if (!isset($this->persisters[$metadata->className])) {
            $joins = [];
            foreach ($metadata->getAssociations() as $association) {
                if ($association->isToOne() && $association->isOwningSide()) {
                    $joins[$association->fieldName] = [(string) $association->joinColumn, $this->metadata->getMetadataFor($association->targetEntity)->getIdField()->type];
                }
            }
            $this->persisters[$metadata->className] = new BasicEntityPersister($this->connection, $metadata, $joins, $this->filters);
        }

        return $this->persisters[$metadata->className];
    }

    public function nextId(ClassMetadata $metadata): int|string|null
    {
        $generator = $this->generators->getGenerator($metadata);

        return null === $generator || $generator->isPostInsert() ? null : $generator->generate($this->connection);
    }

    public function isTransactionActive(): bool
    {
        return $this->connection->isTransactionActive();
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
