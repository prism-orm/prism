<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Les INSERT d'un flush ne peuvent pas être ordonnés : un cycle de FK sans FK nullable pour le
 * rompre (RG-04.4).
 *
 * @api
 */
final class CyclicDependencyException extends \LogicException implements OrmException
{
    public static function forCycle(string $cycle): self
    {
        return new self(\sprintf('Cannot order the INSERTs of this flush: %s is a cycle of non-nullable foreign keys. Make one of these relations nullable.', $cycle));
    }
}
