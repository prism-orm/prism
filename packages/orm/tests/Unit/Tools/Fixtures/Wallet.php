<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tools\Fixtures;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Wallet
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\ManyToOne]
    public Currency $currency;

    /** @var Collection<int, Currency> */
    #[Prism\ManyToMany(targetEntity: Currency::class)]
    public Collection $accepted;

    public function __construct()
    {
        $this->accepted = new ArrayCollection();
    }
}
