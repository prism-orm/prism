<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

enum FixtureStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
}
