<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Type;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Type\ConversionContext;

abstract class TypeTestCase extends TestCase
{
    protected static function context(?string $timezone = null): ConversionContext
    {
        return new ConversionContext(new MySqlPlatform('8.4'), null === $timezone ? null : new \DateTimeZone($timezone));
    }
}
