<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Support;

use Prism\Dbal\Configuration;
use Prism\Dbal\Connection;
use Prism\Dbal\ConnectionParameters;
use Prism\Dbal\Driver;
use Prism\Dbal\DriverManager;

/**
 * Connexion de test à partir de DATABASE_URL.
 *
 * @internal
 */
final class TestDatabase
{
    public static function url(): ?string
    {
        $url = getenv('DATABASE_URL');

        return \is_string($url) && '' !== $url ? $url : null;
    }

    public static function connection(Configuration $configuration = new Configuration()): Connection
    {
        return DriverManager::fromUrl(self::url() ?? throw new \LogicException('DATABASE_URL is not set.'), $configuration);
    }

    public static function driver(): Driver
    {
        return ConnectionParameters::fromUrl(self::url() ?? throw new \LogicException('DATABASE_URL is not set.'))->driver;
    }

    /**
     * Crée une base dédiée à ce lancement de la suite et y redirige DATABASE_URL (RG-00.1).
     *
     * @return \Closure(): void détruit la base créée
     */
    public static function isolate(): \Closure
    {
        $url = self::url() ?? throw new \LogicException('DATABASE_URL is not set.');
        $parameters = ConnectionParameters::fromUrl($url);
        $token = bin2hex(random_bytes(4));

        if (Driver::Sqlite === $parameters->driver) {
            if (':memory:' === $parameters->database) {
                // une base en mémoire est déjà propre à chaque connexion
                return static function (): void {
                };
            }

            $isolated = $parameters->database . '.' . $token;
            self::useUrl((string) preg_replace('#^(sqlite:///)[^?]*#', '${1}' . $isolated, $url));

            return static function () use ($isolated): void {
                if (is_file($isolated)) {
                    unlink($isolated);
                }
            };
        }

        $name = ($parameters->database ?? 'prism_test') . '_' . $token;
        $admin = DriverManager::fromUrl($url);
        $quoted = $admin->getPlatform()->quoteIdentifier($name);
        $force = Driver::PostgreSql === $parameters->driver ? ' WITH (FORCE)' : '';

        $admin->executeStatement('CREATE DATABASE ' . $quoted);
        $admin->close();
        self::useUrl((string) preg_replace('#/[^/?]*(\?.*)?$#', '/' . $name . '$1', $url, 1));

        return static function () use ($url, $quoted, $force): void {
            DriverManager::fromUrl($url)->executeStatement('DROP DATABASE IF EXISTS ' . $quoted . $force);
        };
    }

    private static function useUrl(string $url): void
    {
        putenv('DATABASE_URL=' . $url);
        $_ENV['DATABASE_URL'] = $url;
        $_SERVER['DATABASE_URL'] = $url;
    }
}
