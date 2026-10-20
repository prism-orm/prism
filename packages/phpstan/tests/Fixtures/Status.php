<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures;

enum Status: string
{
    case Draft = 'draft';
    case Paid = 'paid';
}
