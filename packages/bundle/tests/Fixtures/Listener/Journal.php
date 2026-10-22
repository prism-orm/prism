<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Listener;

// une dépendance quelconque, pour vérifier que les listeners sont des services
final class Journal
{
    /** @var list<string> */
    public array $lines = [];
}
