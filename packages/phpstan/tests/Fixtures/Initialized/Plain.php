<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Fixtures\Initialized;

// une classe qui n'est pas une entité reste vérifiée
final class Plain
{
    public string $name;
}
