<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Type;

use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\Type;

// un type de l'application, autoconfiguré avec le tag prism.type
final class MoneyType extends Type
{
    public function getName(): string
    {
        return 'money';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        return \is_int($value) ? \sprintf('%d.%02d', intdiv($value, 100), $value % 100) : null;
    }

    public function toPhp(mixed $value, ConversionContext $context): ?int
    {
        return \is_string($value) ? (int) round((float) $value * 100) : null;
    }
}
