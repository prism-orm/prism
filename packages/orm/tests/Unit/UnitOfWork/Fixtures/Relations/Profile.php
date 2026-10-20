<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Relations;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
final class Profile
{
    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    public function __construct(
        #[Prism\OneToOne(inversedBy: 'profile')]
        public Customer $customer,
        #[Prism\Column(length: 80)]
        public string $bio = '',
    ) {
    }
}
