<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

use Prism\Orm\Mapping as Prism;

final class InvoiceListener
{
    #[Prism\PostPersist]
    public function notify(Invoice $invoice): void
    {
    }
}
