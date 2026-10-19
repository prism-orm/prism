<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Psr\Cache\InvalidArgumentException;

/**
 * @internal
 */
final class InvalidCacheKey extends \InvalidArgumentException implements InvalidArgumentException
{
}
