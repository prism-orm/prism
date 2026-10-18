<?php

declare(strict_types=1);

namespace Prism\Dbal\Query;

/**
 * @api
 */
enum Order: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
