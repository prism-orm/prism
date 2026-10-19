<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Mapping as Prism;

// table « order » : mot réservé (RG-01.3)
#[Prism\Entity]
final class Order
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\ManyToOne(inversedBy: 'orders')]
        public Customer $customer,
        #[Prism\Column(length: 20)]
        public string $reference = '',
        // sans batch fetching (RG-04.13)
        #[Prism\ManyToOne(inversedBy: 'orders', batch: false)]
        public ?Seller $seller = null,
        // chargée par lot dès l'hydratation de la cohorte
        #[Prism\ManyToOne(fetch: Prism\Fetch::Eager)]
        public ?Depot $depot = null,
    ) {
    }
}
