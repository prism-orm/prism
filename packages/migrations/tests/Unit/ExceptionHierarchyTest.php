<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Exception\PrismException;
use Prism\Migrations\Exception\MigrationsException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testPackageExceptionExtendsPrismException(): void
    {
        self::assertTrue(new \ReflectionClass(MigrationsException::class)->implementsInterface(PrismException::class));
    }
}
