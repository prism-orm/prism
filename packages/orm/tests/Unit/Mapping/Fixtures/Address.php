<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Embeddable]
final class Address
{
    public function __construct(
        #[Prism\Column(length: 120)] public readonly string $street,
        #[Prism\Column(length: 10)] public readonly string $zip,
        #[Prism\Column(length: 80)] public readonly string $city,
    ) {
    }
}
