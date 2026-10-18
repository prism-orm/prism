<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Exception\PrismException;
use Prism\Orm\Exception\OrmException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testPackageExceptionExtendsPrismException(): void
    {
        self::assertTrue(new \ReflectionClass(OrmException::class)->implementsInterface(PrismException::class));
    }
}
