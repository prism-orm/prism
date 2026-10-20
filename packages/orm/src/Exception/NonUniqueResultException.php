<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * getOneOrNull() sur une requête qui rend plusieurs entités.
 *
 * @api
 */
final class NonUniqueResultException extends \RuntimeException implements OrmException
{
    /**
     * @param class-string $entity
     */
    public static function forQuery(string $entity, string $sql): self
    {
        return new self(\sprintf('The query returned more than one %s; use getResult(), or narrow the conditions: %s', $entity, $sql));
    }
}
