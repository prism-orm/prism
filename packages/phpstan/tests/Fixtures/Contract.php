<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Contract
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public int $id;

    #[Prism\OneToOne(inversedBy: 'contract')]
    public Client $client;
}
