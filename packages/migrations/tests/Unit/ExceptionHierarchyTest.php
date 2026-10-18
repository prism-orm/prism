<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Migrations\Exception\MigrationsException;
use Prism\Exception\PrismException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testPackageExceptionExtendsPrismException(): void
    {
        self::assertTrue(is_subclass_of(MigrationsException::class, PrismException::class));
    }
}
