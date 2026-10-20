<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query\Fixtures;

use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\Priority;

/**
 * Un agrégat de chaque famille, et un enum groupé.
 */
final class TypeSummary
{
    /**
     * @param numeric-string|null $total
     * @param numeric-string|null $average
     */
    public function __construct(
        public readonly Priority $priority,
        public readonly int $count,
        public readonly ?string $total,
        public readonly ?string $average,
        public readonly ?float $maxFloat,
        public readonly ?int $minBigint,
        public readonly ?\DateTimeImmutable $first,
        public readonly ?\DateTimeImmutable $last,
        public readonly ?string $firstName,
    ) {
    }
}
