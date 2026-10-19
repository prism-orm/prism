<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

/**
 * Action ON DELETE appliquée par la base (RG-04.6).
 *
 * @api
 */
enum ForeignKeyAction: string
{
    case NoAction = 'NO ACTION';
    case Restrict = 'RESTRICT';
    case Cascade = 'CASCADE';
    case SetNull = 'SET NULL';
}
