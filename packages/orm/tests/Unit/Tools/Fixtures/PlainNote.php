<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tools\Fixtures;

use Prism\Orm\Mapping as Prism;

// Note avant de devenir #[Tenanted] : même table
#[Prism\Entity]
#[Prism\Table(name: 'note')]
final class PlainNote
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20, unique: true)]
    public string $code = '';
}
