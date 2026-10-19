<?php

declare(strict_types=1);

namespace Prism\Dbal\Query;

/**
 * @internal
 */
enum QueryType: string
{
    case Select = 'SELECT';
    case Insert = 'INSERT';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
}
