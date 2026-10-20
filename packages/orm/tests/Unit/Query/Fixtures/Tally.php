<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query\Fixtures;

final class Tally
{
    /** @var array<int|string, mixed> */
    public readonly array $extra;

    public function __construct(
        public readonly int $count,
        mixed ...$extra,
    ) {
        $this->extra = $extra;
    }
}
