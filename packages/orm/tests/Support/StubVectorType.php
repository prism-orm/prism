<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\Type;
use Prism\Dbal\Type\TypeRegistry;

/**
 * Remplaçant du type vector (phase 9) pour valider les fixtures qui l'utilisent.
 *
 * @internal
 */
final class StubVectorType extends Type
{
    public static function registry(): TypeRegistry
    {
        return TypeRegistry::withDefaults()->register(new self());
    }

    public function getName(): string
    {
        return 'vector';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        return \is_string($value) ? $value : null;
    }

    public function toPhp(mixed $value, ConversionContext $context): mixed
    {
        return $value;
    }
}
