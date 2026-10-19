<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Prism\Orm\Persister\EntityPersister;

/**
 * @internal
 */
final class InMemoryPersister implements EntityPersister
{
    public function __construct(
        private readonly InMemoryStorage $storage,
        private readonly string $table,
        private readonly string $idColumn,
        private readonly bool $identity,
    ) {
    }

    public function insert(array $row): ?int
    {
        return $this->storage->insert($this->table, $this->idColumn, $this->identity, $row);
    }

    public function update(mixed $id, array $changes, ?array $version = null): int
    {
        return $this->storage->update($this->table, $id, $changes, $version);
    }

    public function delete(mixed $id, ?array $version = null): int
    {
        return $this->storage->delete($this->table, $id, $version);
    }

    public function loadById(mixed $id): ?array
    {
        return $this->storage->load($this->table, $id);
    }
}
