<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Verrouillage optimiste (RG-03.9).
 *
 * Sur un champ int (incrémenté à chaque UPDATE) ou DateTimeImmutable.
 * L'UPDATE porte WHERE version = :ancienne ; 0 ligne affectée lève OptimisticLockException.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Version implements MappingAttribute
{
}
