<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity(readOnly: true)]
final class AuditLine
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column]
    public string $message = '';
}
