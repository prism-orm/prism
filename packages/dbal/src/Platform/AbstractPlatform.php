<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Exception\UnsupportedPlatformException;

/**
 * Seul endroit où vit le SQL propre à un SGBD.
 *
 * Phase 1, étape 1 : version, savepoints et initialisation de session ; le quoting, les types
 * et le DDL arrivent dans les étapes suivantes.
 *
 * @api
 */
abstract class AbstractPlatform
{
    private const string SAVEPOINT_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    /** Version normalisée « majeur.mineur.patch ». */
    public readonly string $version;

    /**
     * @throws UnsupportedPlatformException si la version est illisible ou inférieure au minimum
     */
    final public function __construct(string $serverVersion)
    {
        $this->version = self::normalize($this->getName(), $serverVersion);

        if (version_compare($this->version, $this->getMinimumVersion(), '<')) {
            throw UnsupportedPlatformException::versionTooOld($this->getName(), $this->version, $this->getMinimumVersion());
        }
    }

    /** Nom affiché : « MySQL », « MariaDB », « PostgreSQL », « SQLite ». */
    abstract public function getName(): string;

    /** Version minimale du périmètre 1.0 (ROADMAP, ADR 0003). */
    abstract public function getMinimumVersion(): string;

    /**
     * Requêtes exécutées juste après l'ouverture de la connexion.
     *
     * @return list<string>
     */
    public function getSessionInitSql(): array
    {
        return [];
    }

    /**
     * Vrai si une requête en échec invalide toute la transaction en cours (PostgreSQL).
     */
    public function failedStatementAbortsTransaction(): bool
    {
        return false;
    }

    public function getCreateSavepointSql(string $name): string
    {
        return 'SAVEPOINT ' . self::savepoint($name);
    }

    public function getReleaseSavepointSql(string $name): string
    {
        return 'RELEASE SAVEPOINT ' . self::savepoint($name);
    }

    public function getRollbackSavepointSql(string $name): string
    {
        return 'ROLLBACK TO SAVEPOINT ' . self::savepoint($name);
    }

    // les noms de savepoint sont générés par Connection : on refuse tout le reste plutôt que de les quoter
    private static function savepoint(string $name): string
    {
        if (1 !== preg_match(self::SAVEPOINT_PATTERN, $name)) {
            throw InvalidParameterException::invalidSavepointName($name);
        }

        return $name;
    }

    private static function normalize(string $platform, string $serverVersion): string
    {
        // anciens serveurs MariaDB : « 5.5.5-10.11.2-MariaDB » (préfixe de compatibilité réplication)
        $version = (string) preg_replace('/^5\.5\.5-/', '', $serverVersion);

        if (1 !== preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/i', $version, $m)) {
            throw UnsupportedPlatformException::unparsableVersion($platform, $serverVersion);
        }

        return \sprintf('%d.%d.%d', $m[1], $m[2] ?? 0, $m[3] ?? 0);
    }
}
