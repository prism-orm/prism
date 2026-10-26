<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tools\Fixtures;

use Prism\Orm\Mapping as Prism;

// référentiel mixte : une ligne à tenant_id NULL est partagée par tous les tenants
#[Prism\Entity]
#[Prism\Tenanted(global: true)]
#[Prism\UniqueConstraint(fields: ['code'])]
final class SharedRate
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 10)]
    public string $code = '';
}
