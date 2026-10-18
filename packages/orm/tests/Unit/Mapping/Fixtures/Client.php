<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Tenanted]
final class Client
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    /** @var iterable<int, Invoice> */
    #[Prism\OneToMany(targetEntity: Invoice::class, mappedBy: 'client', fetch: Prism\Fetch::ExtraLazy)]
    public iterable $invoices = [];
}
