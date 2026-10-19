<?php

declare(strict_types=1);

namespace Prism\Orm\Id;

use Prism\Dbal\Connection;
use Prism\Orm\Exception\MappingException;

/**
 * SEQUENCE : l'identifiant est connu avant l'INSERT (utile à l'ordre de commit en phase 4).
 *
 * @internal
 */
final class SequenceGenerator implements IdGenerator
{
    public function __construct(private readonly string $sequence)
    {
    }

    public function isPostInsert(): bool
    {
        return false;
    }

    public function generate(Connection $connection): int|string
    {
        $value = $connection->executeQuery($connection->getPlatform()->getSequenceNextValSql($this->sequence))->fetchOne();
        if (!\is_int($value) && !\is_string($value)) {
            throw MappingException::invalidMetadata($this->sequence, \sprintf('sequence returned %s instead of a number.', get_debug_type($value)));
        }

        return $value;
    }

    public function getSequence(): string
    {
        return $this->sequence;
    }
}
