<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidIdentifierException;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Exception\UnsupportedPlatformException;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

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

    /**
     * Ajoute LIMIT / OFFSET. Les bornes sont des int validés (>= 0) : aucune valeur utilisateur
     * n'atteint le SQL sous forme de texte.
     */
    public function applyLimit(string $sql, ?int $limit, int $offset): string
    {
        if (null !== $limit) {
            $sql .= ' LIMIT ' . $limit;
        }
        if ($offset > 0) {
            $sql .= null === $limit ? $this->getOffsetWithoutLimitSql($offset) : ' OFFSET ' . $offset;
        }

        return $sql;
    }

    /**
     * OFFSET sans LIMIT : PostgreSQL l'accepte tel quel, MySQL et SQLite exigent un LIMIT.
     */
    protected function getOffsetWithoutLimitSql(int $offset): string
    {
        return ' OFFSET ' . $offset;
    }

    /**
     * Nombre maximal de chiffres significatifs qu'un decimal peut garder exactement, null si la
     * plateforme a un vrai type décimal.
     */
    public function getDecimalMaxSignificantDigits(): ?int
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
     * DDL de création d'un schéma complet, dans l'ordre RG-01.10 : séquences, tables, index,
     * puis clés étrangères (les tables cibles existent alors toutes).
     *
     * @return list<string>
     *
     * @throws InvalidSchemaException
     */
    public function getCreateSchemaSql(Schema $schema): array
    {
        $sql = array_map($this->getCreateSequenceSql(...), $schema->getSequences());

        foreach ($schema->getTables() as $table) {
            $sql[] = $this->getCreateTableSql($table);
        }
        foreach ($schema->getTables() as $table) {
            foreach ($table->getIndexes() as $index) {
                $sql[] = $this->getCreateIndexSql($table->name, $index);
            }
        }
        if ($this->supportsAlterForeignKeys()) {
            foreach ($schema->getTables() as $table) {
                foreach ($table->getForeignKeys() as $foreignKey) {
                    $sql[] = $this->getCreateForeignKeySql($table->name, $foreignKey);
                }
            }
        }

        return $sql;
    }

    /**
     * CREATE TABLE seul : les index suivent par getCreateIndexSql(), les clés étrangères par
     * getCreateForeignKeySql() (sauf SQLite, qui les déclare ici).
     *
     * @throws InvalidSchemaException
     */
    public function getCreateTableSql(Table $table): string
    {
        $definitions = array_map(fn (Column $column): string => $this->getColumnDeclarationSql($table, $column), $table->getColumns());

        if (null !== $table->primaryKey && !$this->declaresPrimaryKeyInline($table)) {
            $definitions[] = 'PRIMARY KEY (' . $this->quoteIdentifiers($table->primaryKey) . ')';
        }
        if (!$this->supportsAlterForeignKeys()) {
            foreach ($table->getForeignKeys() as $foreignKey) {
                $definitions[] = $this->getForeignKeyDefinitionSql($foreignKey);
            }
        }

        return 'CREATE TABLE ' . $this->quoteIdentifier($table->name) . ' (' . implode(', ', $definitions) . ')';
    }

    public function getDropTableSql(string $table): string
    {
        return 'DROP TABLE ' . $this->quoteIdentifier($table);
    }

    /**
     * @throws InvalidSchemaException type sans déclaration sur la plateforme
     */
    public function getColumnDeclarationSql(Table $table, Column $column): string
    {
        $sql = $this->quoteIdentifier($column->name) . ' ' . $this->getTypeDeclarationSql($table, $column);
        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';

        if (null !== $column->default) {
            $sql .= ' DEFAULT ' . $this->getDefaultValueSql($column->default);
        }

        return $column->autoincrement ? $sql . $this->getAutoincrementSql() : $sql;
    }

    public function getCreateIndexSql(string $table, Index $index): string
    {
        return \sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $index->unique ? 'UNIQUE ' : '',
            $this->quoteIdentifier($index->name),
            $this->quoteIdentifier($table),
            $this->quoteIdentifiers($index->columns),
        );
    }

    public function getDropIndexSql(string $table, string $index): string
    {
        return 'DROP INDEX ' . $this->quoteIdentifier($index);
    }

    /**
     * @throws InvalidSchemaException plateforme sans ALTER TABLE … ADD CONSTRAINT (SQLite)
     */
    public function getCreateForeignKeySql(string $table, ForeignKey $foreignKey): string
    {
        if (!$this->supportsAlterForeignKeys()) {
            throw InvalidSchemaException::unsupportedFeature($this->getName(), 'adding a foreign key to an existing table (the table must be rebuilt)');
        }

        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD ' . $this->getForeignKeyDefinitionSql($foreignKey);
    }

    /**
     * @throws InvalidSchemaException plateforme sans ALTER TABLE … DROP CONSTRAINT (SQLite)
     */
    public function getDropForeignKeySql(string $table, string $foreignKey): string
    {
        if (!$this->supportsAlterForeignKeys()) {
            throw InvalidSchemaException::unsupportedFeature($this->getName(), 'dropping a foreign key from an existing table (the table must be rebuilt)');
        }

        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP CONSTRAINT ' . $this->quoteIdentifier($foreignKey);
    }

    public function getForeignKeyDefinitionSql(ForeignKey $foreignKey): string
    {
        return \sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
            $this->quoteIdentifier($foreignKey->name),
            $this->quoteIdentifiers($foreignKey->localColumns),
            $this->quoteIdentifier($foreignKey->foreignTable),
            $this->quoteIdentifiers($foreignKey->foreignColumns),
            $foreignKey->onDelete->value,
        );
    }

    public function supportsSequences(): bool
    {
        return false;
    }

    /**
     * @throws InvalidSchemaException plateforme sans séquences (MySQL, SQLite)
     */
    public function getCreateSequenceSql(Sequence $sequence): string
    {
        if (!$this->supportsSequences()) {
            throw InvalidSchemaException::unsupportedFeature($this->getName(), 'sequences');
        }

        return \sprintf('CREATE SEQUENCE %s START WITH %d INCREMENT BY %d', $this->quoteIdentifier($sequence->name), $sequence->start, $sequence->increment);
    }

    /**
     * @throws InvalidSchemaException plateforme sans séquences
     */
    public function getDropSequenceSql(string $sequence): string
    {
        if (!$this->supportsSequences()) {
            throw InvalidSchemaException::unsupportedFeature($this->getName(), 'sequences');
        }

        return 'DROP SEQUENCE ' . $this->quoteIdentifier($sequence);
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
     * Déclaration SQL du type d'une colonne (sans NULL, DEFAULT ni auto-incrément).
     *
     * @throws InvalidSchemaException type inconnu de la plateforme
     */
    abstract protected function getTypeDeclarationSql(Table $table, Column $column): string;

    abstract protected function getAutoincrementSql(): string;

    protected function supportsAlterForeignKeys(): bool
    {
        return true;
    }

    protected function declaresPrimaryKeyInline(Table $table): bool
    {
        return false;
    }

    protected function getBooleanLiteralSql(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function getDefaultValueSql(string|int|float|bool $default): string
    {
        return match (true) {
            \is_bool($default) => $this->getBooleanLiteralSql($default),
            \is_int($default) => (string) $default,
            \is_float($default) => var_export($default, true),
            default => $this->quoteStringLiteral($default),
        };
    }

    /**
     * @param list<string> $names
     */
    protected function quoteIdentifiers(array $names): string
    {
        return implode(', ', array_map($this->quoteIdentifier(...), $names));
    }

    /**
     * @throws InvalidSchemaException
     */
    protected function unsupportedType(Table $table, Column $column): never
    {
        throw InvalidSchemaException::unsupportedType($this->getName(), $table->name, $column->name, $column->type);
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
