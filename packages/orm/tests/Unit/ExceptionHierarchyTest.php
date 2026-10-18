<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\OrmException;
use Prism\Exception\PrismException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testPackageExceptionExtendsPrismException(): void
    {
        self::assertTrue(is_subclass_of(OrmException::class, PrismException::class));
    }
}
