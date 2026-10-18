<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Platform\AbstractPlatform;

/**
 * Ce dont un type a besoin pour convertir une valeur, sans dépendre d'une Connection.
 *
 * @api
 */
final class ConversionContext
{
    /**
     * @param \DateTimeZone|null $timezone fuseau de stockage des datetime (RG-01.6) ; null : aucune conversion
     */
    public function __construct(
        public readonly AbstractPlatform $platform,
        public readonly ?\DateTimeZone $timezone = null,
    ) {
    }
}
