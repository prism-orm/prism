<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidIdentifierException;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Exception\UnsupportedPlatformException;

/**
 * Seul endroit où vit le SQL propre à un SGBD.
 *
 * Tout identifiant généré par Prism est cité, mot réservé ou non (RG-01.3) : pas de liste
 * de mots-clés à maintenir, et un SQL déterministe pour le diff de schéma (RG-01.7).
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
     * Cite un identifiant d'une seule partie : un « . » est conservé tel quel, jamais interprété
     * comme séparateur schéma.table.
     *
     * @throws InvalidIdentifierException nom vide, non UTF-8, avec octet NUL ou trop long
     */
    public function quoteIdentifier(string $name): string
    {
        if ('' === $name) {
            throw InvalidIdentifierException::empty($this->getName());
        }
        if (str_contains($name, "\0") || 1 !== preg_match('//u', $name)) {
            throw InvalidIdentifierException::invalidCharacters($this->getName(), $name);
        }
        $this->assertIdentifierLength($name);

        $quote = $this->getIdentifierQuoteCharacter();

        return $quote . str_replace($quote, $quote . $quote, $name) . $quote;
    }

    /**
     * Littéral chaîne pour le DDL uniquement (valeurs DEFAULT) ; les requêtes lient leurs valeurs
     * en paramètres (RG-01.4).
     *
     * @throws InvalidParameterException valeur contenant un octet NUL
     */
    public function quoteStringLiteral(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw InvalidParameterException::nulByteInLiteral();
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Longueur maximale d'un identifiant, null si la plateforme n'en impose pas.
     */
    public function getMaxIdentifierLength(): ?int
    {
        return null;
    }

    /** Format date() d'un datetime à la seconde (décision : pas de microsecondes en 1.0). */
    public function getDateTimeFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d';
    }

    public function getTimeFormat(): string
    {
        return 'H:i:s';
    }

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

    protected function getIdentifierQuoteCharacter(): string
    {
        return '"';
    }

    /**
     * Par défaut la limite est comptée en octets (PostgreSQL : NAMEDATALEN - 1).
     *
     * @throws InvalidIdentifierException
     */
    protected function assertIdentifierLength(string $name): void
    {
        $max = $this->getMaxIdentifierLength();
        if (null !== $max && \strlen($name) > $max) {
            throw InvalidIdentifierException::tooLong($this->getName(), $name, \strlen($name), $max, 'bytes');
        }
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
