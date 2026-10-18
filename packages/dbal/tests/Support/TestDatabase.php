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
    private const string NAME_PATTERN = '/^[A-Za-z0-9_]+$/';

    public static function url(): ?string
    {
        $url = getenv('DATABASE_URL');

        return \is_string($url) && '' !== $url ? $url : null;
    }

    public static function platform(?string $url = null): string
    {
        $url ??= self::url() ?? throw new \LogicException('DATABASE_URL is not set.');

        return match (true) {
            str_starts_with($url, 'sqlite:') => 'sqlite',
            str_starts_with($url, 'postgresql:'), str_starts_with($url, 'pgsql:') => 'pgsql',
            str_contains($url, 'MariaDB') => 'mariadb',
            str_starts_with($url, 'mysql:') => 'mysql',
            default => throw new \LogicException(\sprintf('Unsupported DATABASE_URL scheme in "%s".', $url)),
        };
    }

    public static function connect(?string $url = null): \PDO
    {
        $url ??= self::url() ?? throw new \LogicException('DATABASE_URL is not set.');
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];

        if (str_starts_with($url, 'sqlite:')) {
            return new \PDO('sqlite:' . self::sqlitePath($url), options: $options);
        }

        $parts = self::parse($url);
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;

        $dsn = match (self::platform($url)) {
            'pgsql' => \sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, self::databaseName($url)),
            default => \sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], $parts['port'] ?? 3306, self::databaseName($url)),
        };

        return new \PDO($dsn, $user, $password, $options);
    }

    /**
     * Crée une base dédiée à ce lancement de la suite et y redirige DATABASE_URL (RG-00.1).
     *
     * @return \Closure(): void détruit la base créée
     */
    public static function isolate(): \Closure
    {
        $url = self::url() ?? throw new \LogicException('DATABASE_URL is not set.');
        $token = bin2hex(random_bytes(4));

        if ('sqlite' === self::platform($url)) {
            $path = self::sqlitePath($url);
            if (':memory:' === $path) {
                // une base en mémoire est déjà propre à chaque connexion
                return static function (): void {
                };
            }

            $isolated = $path . '.' . $token;
            self::useUrl('sqlite://' . $isolated);

            return static function () use ($isolated): void {
                if (is_file($isolated)) {
                    unlink($isolated);
                }
            };
        }

        $name = self::databaseName($url) . '_' . $token;
        $quoted = self::quoteIdentifier($url, $name);

        self::connect($url)->exec('CREATE DATABASE ' . $quoted);
        self::useUrl((string) preg_replace('#/[^/?]*(\?.*)?$#', '/' . $name . '$1', $url, 1));

        $force = 'pgsql' === self::platform($url) ? ' WITH (FORCE)' : '';

        return static function () use ($url, $quoted, $force): void {
            self::connect($url)->exec('DROP DATABASE IF EXISTS ' . $quoted . $force);
        };
    }

    public static function databaseName(?string $url = null): string
    {
        $url ??= self::url() ?? throw new \LogicException('DATABASE_URL is not set.');
        $name = ltrim(self::parse($url)['path'], '/');

        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \LogicException(\sprintf('Invalid database name "%s" in DATABASE_URL.', $name));
        }

        return $name;
    }

    /**
     * @return array{host: string, path: string, port?: int, user?: string, pass?: string}
     */
    private static function parse(string $url): array
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'], $parts['path'])) {
            throw new \LogicException(\sprintf('Invalid DATABASE_URL "%s".', $url));
        }

        return $parts;
    }

    private static function sqlitePath(string $url): string
    {
        $path = substr($url, \strlen('sqlite://'));

        return '/:memory:' === $path ? ':memory:' : $path;
    }

    // provisoire : remplacé par Platform::quoteIdentifier() en phase 1
    private static function quoteIdentifier(string $url, string $name): string
    {
        return 'pgsql' === self::platform($url) ? '"' . $name . '"' : '`' . $name . '`';
    }

    private static function useUrl(string $url): void
    {
        putenv('DATABASE_URL=' . $url);
        $_ENV['DATABASE_URL'] = $url;
        $_SERVER['DATABASE_URL'] = $url;
    }
}
