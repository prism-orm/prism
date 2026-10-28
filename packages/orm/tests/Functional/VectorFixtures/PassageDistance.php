<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\VectorFixtures;

final readonly class PassageDistance
{
    public function __construct(
        public string $title,
        public float $distance,
    ) {
    }
}
