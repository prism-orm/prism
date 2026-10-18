<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

/**
 * Date et heure à la seconde. Avec un fuseau de stockage (RG-01.6), la valeur est écrite dans ce
 * fuseau et relue dans ce fuseau ; sans fuseau, aucune conversion.
 *
 * @api
 */
final class DateTimeImmutableType extends Type
{
    public function getName(): string
    {
        return 'datetime_immutable';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        return TemporalValue::toDatabase($this->getName(), $value, $context->platform->getDateTimeFormat(), $context->timezone);
    }

    public function toPhp(mixed $value, ConversionContext $context): ?\DateTimeImmutable
    {
        return TemporalValue::toPhp($this->getName(), $value, $context->platform->getDateTimeFormat(), $context->timezone);
    }
}
