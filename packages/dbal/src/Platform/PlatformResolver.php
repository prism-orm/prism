<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\ConnectionParameters;
use Prism\Dbal\Driver;
use Prism\Dbal\Exception\UnsupportedPlatformException;

/**
 * Choisit la plateforme à partir du driver et de la version du serveur (déclarée ou détectée).
 *
 * @internal
 */
final class PlatformResolver
{
    /**
     * @throws UnsupportedPlatformException
     */
    public static function resolve(ConnectionParameters $parameters, string $serverVersion): AbstractPlatform
    {
        return match ($parameters->driver) {
            Driver::Sqlite => new SqlitePlatform($serverVersion),
            Driver::PostgreSql => new PostgreSqlPlatform($serverVersion),
            Driver::MySql => $parameters->mariaDb || false !== stripos($serverVersion, 'mariadb')
                ? new MariaDbPlatform($serverVersion)
                : new MySqlPlatform($serverVersion),
        };
    }
}
