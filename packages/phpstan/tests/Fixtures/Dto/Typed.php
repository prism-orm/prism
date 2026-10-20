<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures\Dto;

use Prism\PHPStan\Tests\Fixtures\Status;

final class Typed
{
    /**
     * @param array<mixed> $meta
     */
    public function __construct(
        public readonly Status $status,
        public readonly ?\DateTimeImmutable $paidAt,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly ?float $discount,
        public readonly string $city,
        public readonly ?string $zip,
        public readonly array $meta,
    ) {
    }
}
