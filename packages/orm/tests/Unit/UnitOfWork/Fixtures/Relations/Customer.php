<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Customer
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 60)]
    public string $name = '';

    // cycle avec PurchaseOrder::$customer : nullable, rompt le cycle (RG-04.4)
    #[Prism\ManyToOne]
    public ?PurchaseOrder $lastOrder = null;

    // sans cascade : une cible NEW lève UnpersistedRelationException (RG-04.2)
    #[Prism\ManyToOne]
    public ?Customer $referrer = null;

    // côté inverse, readonly : relu par une requête sur profile.customer_id, écrit une fois
    #[Prism\OneToOne(mappedBy: 'customer')]
    public readonly ?Profile $profile;

    // à l'hydratation, le constructeur n'est pas appelé (RG-03.12) : l'ORM écrit $profile
    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->profile = null;
    }
}
