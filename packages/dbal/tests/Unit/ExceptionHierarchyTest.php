<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\DbalException;
use Prism\Exception\PrismException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testPackageExceptionExtendsPrismException(): void
    {
        self::assertTrue(is_subclass_of(DbalException::class, PrismException::class));
    }
}
