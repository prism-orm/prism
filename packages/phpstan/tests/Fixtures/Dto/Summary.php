<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures\Dto;

final class Summary
{
    /**
     * @param numeric-string      $total
     * @param numeric-string|null $average
     */
    public function __construct(
        public readonly string $reference,
        public readonly string $total,
        public readonly int $count,
        public readonly ?string $average = null,
    ) {
    }
}
