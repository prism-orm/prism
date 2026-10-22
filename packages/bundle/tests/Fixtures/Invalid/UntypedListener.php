<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Invalid;

use Prism\Bundle\Attribute\AsPrismListener;

#[AsPrismListener]
final class UntypedListener
{
    public function __invoke(object $event): void
    {
    }
}
