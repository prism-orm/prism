<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Listener;

use Prism\Bundle\Attribute\AsEntityListener;
use Prism\Bundle\Tests\Fixtures\Entity\Main\Customer;
use Prism\Orm\Mapping\PrePersist;

#[AsEntityListener]
final readonly class CustomerListener
{
    public function __construct(private Journal $journal)
    {
    }

    #[PrePersist]
    public function prePersist(Customer $customer): void
    {
        $this->journal->lines[] = 'pre-persist ' . $customer->name;
    }
}
