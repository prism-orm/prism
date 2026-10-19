<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Mapping as Prism;

// RG-03.11 : suivi explicite
#[Prism\Entity(trackingPolicy: Prism\TrackingPolicy::Explicit)]
final class Note
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public string $body = '';
}
