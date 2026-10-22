<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Messenger;

final readonly class Restock
{
    public function __construct(public string $ref, public bool $fail = false)
    {
    }
}
