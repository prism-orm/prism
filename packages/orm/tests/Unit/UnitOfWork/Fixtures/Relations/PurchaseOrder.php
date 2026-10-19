<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class PurchaseOrder
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 20)]
    public string $reference = '';

    #[Prism\ManyToOne(cascade: [Prism\Cascade::Persist])]
    public Customer $customer;

    public function __construct(Customer $customer, string $reference = '')
    {
        $this->customer = $customer;
        $this->reference = $reference;
    }
}
