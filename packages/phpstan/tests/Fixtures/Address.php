<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Embeddable]
final class Address
{
    #[Prism\Column(length: 80)]
    public string $city;

    #[Prism\Column(length: 10)]
    public ?string $zip = null;
}
