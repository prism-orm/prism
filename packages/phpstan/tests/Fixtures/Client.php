<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures;

use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;

// sans constructeur : ses propriétés mappées sont hydratées (RG-03.12), l'extension le sait
#[Prism\Entity]
final class Client
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public int $id;

    #[Prism\Column(length: 80)]
    public string $name;

    #[Prism\Column(length: 120)]
    public ?string $email;

    /** @var Collection<int, Invoice> */
    #[Prism\OneToMany(targetEntity: Invoice::class, mappedBy: 'client')]
    public Collection $invoices;

    #[Prism\OneToOne(mappedBy: 'client')]
    public ?Contract $contract;
}
