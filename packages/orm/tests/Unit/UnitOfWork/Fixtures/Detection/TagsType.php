<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Detection;

use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\Type;

final class TagsType extends Type
{
    public function getName(): string
    {
        return 'tags';
    }

    public function toDatabase(mixed $value, ConversionContext $context): ?string
    {
        return $value instanceof Tags ? implode(',', $value->items) : null;
    }

    public function toPhp(mixed $value, ConversionContext $context): ?Tags
    {
        return \is_string($value) ? new Tags('' === $value ? [] : explode(',', $value)) : null;
    }
}
