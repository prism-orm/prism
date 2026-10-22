<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Entity\Shop;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Coupon
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::UuidV7)]
    #[Prism\Column(type: 'uuid')]
    public ?string $id = null;

    #[Prism\Column(length: 20)]
    public string $code = '';
}
