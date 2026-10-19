<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional\PersisterFixtures;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity(repositoryClass: CustomerRepository::class)]
final class Customer
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    public function __construct(
        #[Prism\Column(length: 60)]
        public string $name = '',
        #[Prism\Column(length: 40)]
        public string $city = '',
    ) {
    }
}
