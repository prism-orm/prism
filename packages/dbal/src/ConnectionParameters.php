<?php

declare(strict_types=1);

namespace Prism\Dbal;

use Prism\Dbal\Exception\InvalidUrlException;

/**
 * URL de connexion décomposée, au format Symfony (RG-00.2) :
 *
 * - mysql://user:pass@host:3306/db?serverVersion=8.4
 * - mariadb://user:pass@host:3306/db?serverVersion=11.8
 * - postgresql://user:pass@host:5432/db?serverVersion=16 (alias : postgres, pgsql)
 * - sqlite:///:memory:, sqlite:///var/app.db (relatif), sqlite:////srv/app.db (absolu)
 *
 * Le mot de passe n'est exposé qu'au driver, jamais dans un message ni un dump.
 *
 * @internal
 */
final class ConnectionParameters
{
    private const array SCHEMES = [
        'mysql' => Driver::MySql,
        'mariadb' => Driver::MySql,
        'postgresql' => Driver::PostgreSql,
        'postgres' => Driver::PostgreSql,
        'pgsql' => Driver::PostgreSql,
        'sqlite' => Driver::Sqlite,
    ];

    private const array QUERY_OPTIONS = ['serverVersion', 'charset'];

    private function __construct(
        public readonly Driver $driver,
        public readonly bool $mariaDb,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $user,
        #[\SensitiveParameter]
        private readonly ?string $password,
        /** nom de la base, ou chemin du fichier (« :memory: ») pour SQLite */
        public readonly ?string $database,
        public readonly ?string $serverVersion,
        public readonly string $charset,
    ) {
    }

    public static function fromUrl(#[\SensitiveParameter] string $url): self
    {
        $scheme = strtolower((string) strstr($url, '://', true));
        $driver = self::SCHEMES[$scheme] ?? throw InvalidUrlException::because($url, \sprintf('unsupported scheme "%s"; expected one of %s.', $scheme, implode(', ', array_keys(self::SCHEMES))));

        return Driver::Sqlite === $driver ? self::sqlite($url) : self::server($url, $driver, 'mariadb' === $scheme);
    }

    public function getDsn(): string
    {
        if (Driver::Sqlite === $this->driver) {
            return 'sqlite:' . $this->database;
        }

        $dsn = \sprintf('%s:host=%s;port=%d', $this->driver->value, $this->host, $this->port);
        if (null !== $this->database) {
            $dsn .= ';dbname=' . $this->database;
        }

        return Driver::MySql === $this->driver ? $dsn . ';charset=' . $this->charset : $dsn;
    }

    /**
     * Les mêmes paramètres, pour se connecter au serveur sans la base : pour la créer ou la
     * supprimer. PostgreSQL se connecte toujours à une base : celle d'administration, postgres.
     */
    public function withoutDatabase(): self
    {
        if (Driver::Sqlite === $this->driver) {
            return $this;
        }

        return new self($this->driver, $this->mariaDb, $this->host, $this->port, $this->user, $this->password, Driver::PostgreSql === $this->driver ? 'postgres' : null, $this->serverVersion, $this->charset);
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /** Cible lisible pour les messages : jamais d'identifiants. */
    public function describe(): string
    {
        if (Driver::Sqlite === $this->driver) {
            return \sprintf('SQLite database "%s"', $this->database);
        }

        return \sprintf('%s server %s:%d%s', $this->mariaDb ? 'MariaDB' : $this->driver->name, $this->host, $this->port, null === $this->database ? '' : \sprintf(' (database "%s")', $this->database));
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['dsn' => $this->getDsn(), 'user' => $this->user, 'password' => null === $this->password ? null : '***', 'serverVersion' => $this->serverVersion];
    }

    private static function sqlite(#[\SensitiveParameter] string $url): self
    {
        [$location, $query] = array_pad(explode('?', substr($url, \strlen('sqlite://')), 2), 2, '');
        if (!str_starts_with($location, '/') || '/' === $location) {
            throw InvalidUrlException::because($url, 'expected sqlite:///:memory: or sqlite:///path/to/file.db.');
        }

        $options = self::options($url, $query);

        return new self(Driver::Sqlite, false, null, null, null, null, rawurldecode(substr($location, 1)), $options['serverVersion'] ?? null, 'utf8');
    }

    private static function server(#[\SensitiveParameter] string $url, Driver $driver, bool $mariaDb): self
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host']) || '' === $parts['host']) {
            throw InvalidUrlException::because($url, 'missing host.');
        }

        $database = isset($parts['path']) ? rawurldecode(ltrim($parts['path'], '/')) : '';
        foreach (['host' => $parts['host'], 'database' => $database] as $name => $value) {
            // ces valeurs sont insérées dans le DSN PDO, où « ; » sépare les options
            if (1 === preg_match('/[;=\s]/', $value)) {
                throw InvalidUrlException::because($url, \sprintf('the %s must not contain ";", "=" or spaces.', $name));
            }
        }

        $options = self::options($url, $parts['query'] ?? '');

        return new self(
            $driver,
            $mariaDb,
            $parts['host'],
            $parts['port'] ?? (Driver::PostgreSql === $driver ? 5432 : 3306),
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            '' === $database ? null : $database,
            $options['serverVersion'] ?? null,
            $options['charset'] ?? 'utf8mb4',
        );
    }

    /**
     * @return array<string, string>
     */
    private static function options(#[\SensitiveParameter] string $url, string $query): array
    {
        parse_str($query, $raw);
        $options = [];

        foreach ($raw as $name => $value) {
            if (!\in_array($name, self::QUERY_OPTIONS, true)) {
                throw InvalidUrlException::because($url, \sprintf('unknown option "%s"; supported options are %s.', $name, implode(', ', self::QUERY_OPTIONS)));
            }
            if (!\is_string($value) || '' === $value) {
                throw InvalidUrlException::because($url, \sprintf('option "%s" must be a non-empty string.', $name));
            }
            $options[$name] = $value;
        }

        return $options;
    }
}
