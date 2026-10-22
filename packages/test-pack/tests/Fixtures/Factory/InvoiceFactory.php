<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Fixtures\Factory;

use Prism\TestPack\Factory\Factory;
use Prism\TestPack\Tests\Fixtures\Entity\Billing\Invoice;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected static function class(): string
    {
        return Invoice::class;
    }

    protected function defaults(): array
    {
        return ['total' => '10.00'];
    }
}
