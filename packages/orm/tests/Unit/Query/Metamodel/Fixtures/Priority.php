<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures;

enum Priority: int
{
    case Low = 1;
    case High = 2;
}
