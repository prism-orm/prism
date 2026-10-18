<?php

declare(strict_types=1);

namespace Prism\Dbal;

/**
 * Driver PDO utilisé ; MySQL et MariaDB partagent pdo_mysql.
 *
 * @internal
 */
enum Driver: string
{
    case MySql = 'mysql';
    case PostgreSql = 'pgsql';
    case Sqlite = 'sqlite';
}
