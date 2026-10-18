<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

/**
 * Heure seule à la seconde, rendue au 1970-01-01. Jamais convertie de fuseau.
 *
 * @api
 */
final class TimeImmutableType extends Type
{
    public function getName(): string
    {
        return 'time_immutable';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        return TemporalValue::toDatabase($this->getName(), $value, $context->platform->getTimeFormat(), null);
    }

    public function toPhp(mixed $value, ConversionContext $context): ?\DateTimeImmutable
    {
        return TemporalValue::toPhp($this->getName(), $value, $context->platform->getTimeFormat(), null);
    }
}
