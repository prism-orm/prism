<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Fixtures\Entity\Billing;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table('tp_invoice')]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total = '0.00';
}
