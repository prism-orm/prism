<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity(readOnly: true)]
#[Prism\Tenanted(global: true)]
final class Tag
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Sequence, sequenceName: 'tag_seq')]
    public ?int $id = null;

    /** @var iterable<int, Invoice> */
    #[Prism\ManyToMany(targetEntity: Invoice::class, mappedBy: 'tags')]
    public iterable $invoices = [];
}
