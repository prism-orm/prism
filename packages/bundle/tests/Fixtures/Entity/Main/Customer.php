<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Entity\Main;

use Prism\Bundle\Tests\Fixtures\Listener\CustomerListener;
use Prism\Bundle\Tests\Fixtures\Repository\CustomerRepository;
use Prism\Orm\Mapping as Prism;

#[Prism\Entity(repositoryClass: CustomerRepository::class)]
#[Prism\EntityListeners([CustomerListener::class])]
final class Customer
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 120)]
    public string $name = '';
}
