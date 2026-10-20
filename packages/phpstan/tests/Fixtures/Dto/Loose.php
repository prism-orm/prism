<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures\Dto;

final class Loose
{
    /** @var array<int|string, mixed> */
    public readonly array $values;

    public function __construct(mixed ...$values)
    {
        $this->values = $values;
    }
}
