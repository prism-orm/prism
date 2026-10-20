<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Account
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\OneToOne(inversedBy: 'account')]
        public Customer $customer,
        #[Prism\Column]
        public int $balance = 0,
    ) {
    }
}
