<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\Exception\BundleException;
use Prism\Exception\PrismException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testPackageExceptionExtendsPrismException(): void
    {
        self::assertTrue(is_subclass_of(BundleException::class, PrismException::class));
    }
}
