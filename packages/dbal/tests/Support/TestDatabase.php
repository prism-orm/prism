<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Support;

/**
 * Ouvre une connexion PDO de test à partir de DATABASE_URL.
 *
 * Temporaire : sera remplacé par DriverManager::fromUrl() en phase 1.
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

    public static function platform(): string
    {
        $url = self::url() ?? throw new \LogicException('DATABASE_URL is not set.');

        return match (true) {
            str_starts_with($url, 'sqlite:') => 'sqlite',
            str_starts_with($url, 'postgresql:'), str_starts_with($url, 'pgsql:') => 'pgsql',
            str_contains($url, 'MariaDB') => 'mariadb',
            str_starts_with($url, 'mysql:') => 'mysql',
            default => throw new \LogicException(\sprintf('Unsupported DATABASE_URL scheme in "%s".', $url)),
        };
    }

    public static function connect(): \PDO
    {
        $url = self::url() ?? throw new \LogicException('DATABASE_URL is not set.');
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];

        if (str_starts_with($url, 'sqlite:')) {
            $path = substr($url, \strlen('sqlite://'));
            $path = '/:memory:' === $path ? ':memory:' : $path;

            return new \PDO('sqlite:' . $path, options: $options);
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'], $parts['path'])) {
            throw new \LogicException(\sprintf('Invalid DATABASE_URL "%s".', $url));
        }

        $dbName = ltrim($parts['path'], '/');
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;

        $dsn = match (self::platform()) {
            'pgsql' => \sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, $dbName),
            default => \sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], $parts['port'] ?? 3306, $dbName),
        };

        return new \PDO($dsn, $user, $password, $options);
    }
}
