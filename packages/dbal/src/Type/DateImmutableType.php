<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

/**
 * Date seule, à minuit. Une date n'a pas de fuseau : elle n'est jamais convertie.
 *
 * @internal
 */
final class DateImmutableType extends Type
{
    public function getName(): string
    {
        return 'date_immutable';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        return TemporalValue::toDatabase($this->getName(), $value, $context->platform->getDateFormat(), null);
    }

    public function toPhp(mixed $value, ConversionContext $context): ?\DateTimeImmutable
    {
        return TemporalValue::toPhp($this->getName(), $value, $context->platform->getDateFormat(), null);
    }
}
