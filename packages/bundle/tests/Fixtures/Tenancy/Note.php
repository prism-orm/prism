<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Tenancy;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table(name: 'tenant_note')]
#[Prism\Tenanted]
final class Note
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 40)]
        public string $title = '',
    ) {
    }
}
