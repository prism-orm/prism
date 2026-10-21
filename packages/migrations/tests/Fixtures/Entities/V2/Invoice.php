<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Entities\V2;

use Prism\Orm\Mapping as Prism;

#[Prism\Entity]
#[Prism\Table('df_bill')]
#[Prism\Index(['ref'])]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 20)]
    public string $ref = '';

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total = '0.00';

    public function __construct(
        #[Prism\ManyToOne]
        public Client $client,
    ) {
    }
}
