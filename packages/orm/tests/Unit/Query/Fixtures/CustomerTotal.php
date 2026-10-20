<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query\Fixtures;

final class CustomerTotal
{
    public function __construct(
        public readonly ?string $customer,
        public readonly int $orders,
        public readonly ?string $last = null,
    ) {
    }
}
