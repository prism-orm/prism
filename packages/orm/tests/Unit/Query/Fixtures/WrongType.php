<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Query\Fixtures;

// $name reçoit une chaîne : le type ne correspond pas (RG-05.7)
final class WrongType
{
    public function __construct(
        public readonly int $name,
    ) {
    }
}
