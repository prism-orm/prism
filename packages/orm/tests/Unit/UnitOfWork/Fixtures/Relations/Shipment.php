<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Mapping as Prism;

// entité final, relation readonly : écrite une fois à l'hydratation (RG-02.6)
#[Prism\Entity]
final class Shipment
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\ManyToOne]
        public readonly PurchaseOrder $order,
        #[Prism\Column(length: 20)]
        public string $carrier = '',
        // relation ordinaire après une relation readonly
        #[Prism\ManyToOne]
        public ?Customer $recipient = null,
    ) {
    }
}
