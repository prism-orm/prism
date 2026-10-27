<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidIdentifierException;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Exception\UnsupportedPlatformException;
use Prism\Dbal\LockMode;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ColumnDiff;
use Prism\Dbal\Schema\ForeignKey;
use Prism\Dbal\Schema\Index;
use Prism\Dbal\Schema\IndexKind;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Schema\TableDiff;
use Prism\Dbal\Schema\VectorDistance;

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
     * Valeur scalaire d'un chemin JSON, en texte ; NULL si le chemin manque ou vaut null en JSON.
     * Le chemin est une structure fixée par le code, pas une valeur : ses clés sont validées
     * ([A-Za-z0-9_]+, index entiers) puis écrites en littéral (ADR 0010).
     *
     * @param list<string|int> $path
     *
     * @throws InvalidParameterException chemin vide, clé ou index invalide
     */
    abstract public function getJsonExtractTextSql(string $expression, array $path): string;

    /**
     * Vrai si la plateforme stocke, indexe et compare des vecteurs (ADR 0017).
     */
    public function supportsVectors(): bool
    {
        return false;
    }

    /**
     * Le SQL d'un paramètre vecteur lié en texte (« [0.5,-1.25,3] ») : MariaDB et PostgreSQL ne
     * le lisent qu'à travers une conversion.
     *
     * @throws InvalidSchemaException plateforme sans vecteurs (MySQL)
     */
    public function getVectorParameterSql(string $placeholder): string
    {
        throw InvalidSchemaException::unsupportedVector($this->getName());
    }

    /**
     * Distance entre une colonne vecteur et une expression vecteur (le SQL d'un paramètre).
     * Toujours une distance, plus petite = plus proche ; laissée nue, pour que l'index serve à
     * « ORDER BY distance LIMIT n » (MariaDB n'utilise son index que sous cette forme).
     *
     * @throws InvalidSchemaException plateforme sans vecteurs, ou distance qu'elle ne calcule pas
     */
    public function getVectorDistanceSql(VectorDistance $distance, string $column, string $vector): string
    {
        throw InvalidSchemaException::unsupportedVector($this->getName());
    }

    /**
     * Conversion SQL d'une expression texte en entier ou en décimal.
     *
     * @param 'integer'|'decimal' $type
     */
    public function getCastSql(string $expression, string $type): string
    {
        return \sprintf('CAST(%s AS %s)', $expression, 'integer' === $type ? 'INTEGER' : 'NUMERIC');
    }

    /**
     * Chemin JSON au format SQL/JSON, clés entre guillemets : $."lines"[0]."qty".
     *
     * @param list<string|int> $path
     *
     * @throws InvalidParameterException
     */
    protected function getJsonPathLiteral(array $path): string
    {
        $literal = '$';
        foreach (self::validJsonPath($path) as $key) {
            $literal .= \is_int($key) ? '[' . $key . ']' : '."' . $key . '"';
        }

        return $this->quoteStringLiteral($literal);
    }

    /**
     * @param list<string|int> $path
     *
     * @return list<string|int>
     *
     * @throws InvalidParameterException
     */
    protected static function validJsonPath(array $path): array
    {
        if ([] === $path) {
            throw InvalidParameterException::invalidJsonPath('it is empty');
        }
        foreach ($path as $key) {
            if (\is_int($key) ? $key < 0 : 1 !== preg_match('/^[A-Za-z0-9_]+$/', $key)) {
                throw InvalidParameterException::invalidJsonPath(\sprintf('%s is neither a key of letters, digits and underscores nor an array index', var_export($key, true)));
            }
        }

        return $path;
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
     * Clause de verrou ajoutée en fin de SELECT ; vide pour un verrou optimiste.
     */
    public function getLockSql(LockMode $mode): string
    {
        return match ($mode) {
            LockMode::Optimistic => '',
            LockMode::PessimisticRead => ' FOR SHARE',
            LockMode::PessimisticWrite => ' FOR UPDATE',
        };
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
            foreach ($this->getCanonicalTable($table)->getIndexes() as $index) {
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
     * SQL qui applique le diff, dans l'ordre RG-01.10 : suppression des clés étrangères, des index
     * et des tables, création des tables, modification des colonnes, création des index puis des
     * clés étrangères. Les séquences sont créées avant les tables et supprimées après.
     *
     * Sous MySQL et MariaDB, chaque DDL valide implicitement la transaction : un échec laisse la
     * base à mi-chemin (RG-06.6). Préférer SchemaManager::migrate(), qui gère les transactions.
     *
     * @return list<string>
     *
     * @throws InvalidSchemaException opération impossible sur la plateforme
     */
    public function getMigrationSql(SchemaDiff $diff): array
    {
        $sql = [];

        // 1. clés étrangères : celles retirées, et toutes celles des tables supprimées (références croisées)
        if ($this->supportsAlterForeignKeys()) {
            foreach ($diff->droppedTables as $table) {
                foreach ($table->getForeignKeys() as $foreignKey) {
                    $sql[] = $this->getDropForeignKeySql($table->name, $foreignKey->name);
                }
            }
        }
        foreach ($diff->alteredTables as $table) {
            foreach ($table->droppedForeignKeys as $foreignKey) {
                array_push($sql, ...$this->getDropForeignKeyStatements($table, $foreignKey));
            }
        }

        // 2. index
        foreach ($diff->alteredTables as $table) {
            foreach ($table->droppedIndexes as $index) {
                $sql[] = $this->getDropIndexDefinitionSql($table->name, $index);
            }
        }

        // 3. tables, puis séquences
        foreach ($diff->droppedTables as $table) {
            $sql[] = $this->getDropTableSql($table->name);
        }
        foreach ($diff->droppedSequences as $sequence) {
            $sql[] = $this->getDropSequenceSql($sequence->name);
        }

        // 4. séquences, puis tables
        foreach ($diff->createdSequences as $sequence) {
            $sql[] = $this->getCreateSequenceSql($sequence);
        }
        foreach ($diff->alteredSequences as $sequence) {
            $sql[] = $this->getAlterSequenceSql($sequence);
        }
        foreach ($diff->createdTables as $table) {
            $sql[] = $this->getCreateTableSql($table);
        }

        // 5. colonnes et clé primaire
        foreach ($diff->alteredTables as $table) {
            array_push($sql, ...$this->getAlterColumnsSql($table));
        }

        // 6. index
        foreach ($diff->createdTables as $table) {
            foreach ($table->getIndexes() as $index) {
                $sql[] = $this->getCreateIndexSql($table->name, $index);
            }
        }
        foreach ($diff->alteredTables as $table) {
            foreach ($table->addedIndexes as $index) {
                $sql[] = $this->getCreateIndexSql($table->name, $index);
            }
        }

        // 7. clés étrangères (SQLite : déclarées dans le CREATE TABLE)
        if ($this->supportsAlterForeignKeys()) {
            foreach ($diff->createdTables as $table) {
                foreach ($table->getForeignKeys() as $foreignKey) {
                    $sql[] = $this->getCreateForeignKeySql($table->name, $foreignKey);
                }
            }
        }
        if ($this->supportsAlterForeignKeys()) {
            foreach ($diff->alteredTables as $table) {
                foreach ($table->addedForeignKeys as $foreignKey) {
                    $sql[] = $this->getCreateForeignKeySql($table->name, $foreignKey);
                }
            }
        }

        return $sql;
    }

    public function getAddColumnSql(Table $table, Column $column): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table->name) . ' ADD COLUMN ' . $this->getColumnDeclarationSql($table, $column);
    }

    public function getDropColumnSql(string $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' DROP COLUMN ' . $this->quoteIdentifier($column);
    }

    /**
     * @param list<string> $columns
     */
    public function getAddPrimaryKeySql(string $table, array $columns): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table) . ' ADD PRIMARY KEY (' . $this->quoteIdentifiers($columns) . ')';
    }

    /**
     * @throws InvalidSchemaException plateforme sans séquences
     */
    public function getAlterSequenceSql(Sequence $sequence): string
    {
        if (!$this->supportsSequences()) {
            throw InvalidSchemaException::unsupportedFeature($this->getName(), 'sequences');
        }

        return \sprintf('ALTER SEQUENCE %s INCREMENT BY %d', $this->quoteIdentifier($sequence->name), $sequence->increment);
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

    /**
     * Création d'un index sans bloquer les écritures, quand la plateforme le sait (PostgreSQL :
     * CONCURRENTLY, hors transaction) ; sinon, la création ordinaire.
     */
    public function getCreateIndexConcurrentlySql(string $table, Index $index): string
    {
        return $this->getCreateIndexSql($table, $index);
    }

    /**
     * Vrai si getCreateIndexConcurrentlySql() crée l'index sans verrou, hors transaction.
     */
    public function supportsConcurrentIndexCreation(): bool
    {
        return false;
    }

    /**
     * Un index partiel (whereNull) porte « WHERE colonne IS NULL » (PostgreSQL, SQLite). Un index
     * vectoriel ou plein texte n'a pas de forme commune : chaque plateforme le déclare.
     *
     * @throws InvalidSchemaException index vectoriel ou plein texte sur une plateforme qui n'en a pas
     */
    public function getCreateIndexSql(string $table, Index $index): string
    {
        if (IndexKind::Regular !== $index->kind) {
            throw InvalidSchemaException::unsupportedFeature($this->getName(), IndexKind::Vector === $index->kind ? 'vector indexes' : 'full-text indexes');
        }

        return \sprintf(
            'CREATE %sINDEX %s ON %s (%s)%s',
            $index->unique ? 'UNIQUE ' : '',
            $this->quoteIdentifier($index->name),
            $this->quoteIdentifier($table),
            $this->quoteIdentifiers($index->columns),
            null === $index->whereNull ? '' : ' WHERE ' . $this->quoteIdentifier($index->whereNull) . ' IS NULL',
        );
    }

    /**
     * Suppression d'un index connu par sa définition : celle d'un index partiel peut aller au-delà
     * de l'index (colonne virtuelle de MySQL et MariaDB).
     */
    public function getDropIndexDefinitionSql(string $table, Index $index): string
    {
        return $this->getDropIndexSql($table, $index->name);
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
     * Forme d'une colonne telle que la plateforme la conserve : ce que l'introspection relira.
     * Le Comparator compare des colonnes canoniques (RG-01.8).
     */
    public function getCanonicalColumn(Column $column): Column
    {
        return 'string' === $column->type && null === $column->length ? $column->withType('string', 255) : $column;
    }

    /**
     * Forme d'un index telle que la plateforme la conserve ; null : la plateforme ne le crée pas
     * (index vectoriel ou plein texte sous SQLite, ADR 0017).
     */
    public function getCanonicalIndex(Index $index): ?Index
    {
        return $index;
    }

    public function getCanonicalTable(Table $table): Table
    {
        foreach ($table->getColumns() as $column) {
            $table = $table->withColumn($this->getCanonicalColumn($column));
        }
        foreach ($table->getIndexes() as $index) {
            $canonical = $this->getCanonicalIndex($index);
            $table = null === $canonical ? $table->withoutIndex($index->name) : $table->withIndex($canonical);
        }

        return $table;
    }

    /**
     * Requête des tables de la base courante (colonne « name »), sans tables système.
     *
     * @internal utilisé par le SchemaManager de la plateforme
     */
    abstract public function getListTablesSql(): string;

    /**
     * @internal un paramètre lié : le nom de la table
     */
    abstract public function getListColumnsSql(): string;

    /**
     * @internal un paramètre lié : le nom de la table
     */
    abstract public function getListIndexesSql(): string;

    /**
     * @internal un paramètre lié : le nom de la table
     */
    abstract public function getListForeignKeysSql(): string;

    /**
     * @throws InvalidSchemaException plateforme sans bases nommées (SQLite : un fichier)
     */
    public function getCreateDatabaseSql(string $database): string
    {
        $this->assertNamedDatabases();

        return 'CREATE DATABASE ' . $this->quoteIdentifier($database);
    }

    /**
     * @throws InvalidSchemaException plateforme sans bases nommées
     */
    public function getDropDatabaseSql(string $database): string
    {
        $this->assertNamedDatabases();

        return 'DROP DATABASE ' . $this->quoteIdentifier($database);
    }

    /**
     * Les bases du serveur, une par ligne, en première colonne.
     *
     * @throws InvalidSchemaException plateforme sans bases nommées
     */
    public function getListDatabasesSql(): string
    {
        throw InvalidSchemaException::unsupportedFeature($this->getName(), 'named databases (a SQLite database is a file)');
    }

    /**
     * @throws InvalidSchemaException
     */
    private function assertNamedDatabases(): void
    {
        $this->getListDatabasesSql();
    }

    /**
     * Renommage explicite d'une table (ADR 0012) ; le Comparator ne le devine jamais (RG-01.9).
     * Les index et clés étrangères suivent la table.
     */
    public function getRenameTableSql(string $from, string $to): string
    {
        return \sprintf('ALTER TABLE %s RENAME TO %s', $this->quoteIdentifier($from), $this->quoteIdentifier($to));
    }

    /**
     * Renommage explicite d'une colonne (ADR 0012) : MySQL 8.0, MariaDB 10.5, PostgreSQL et
     * SQLite 3.25 l'écrivent de la même façon, et y adaptent index et clés étrangères.
     */
    public function getRenameColumnSql(string $table, string $from, string $to): string
    {
        return \sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $this->quoteIdentifier($table), $this->quoteIdentifier($from), $this->quoteIdentifier($to));
    }

    /**
     * Renommage explicite d'une séquence (ADR 0012) : garde son compteur, quand sa table est
     * renommée sous PostgreSQL ({table}_id_seq).
     *
     * @throws InvalidSchemaException plateforme sans séquences (MySQL, SQLite)
     */
    public function getRenameSequenceSql(string $from, string $to): string
    {
        if (!$this->supportsSequences()) {
            throw InvalidSchemaException::unsupportedFeature($this->getName(), 'sequences');
        }

        return \sprintf('ALTER SEQUENCE %s RENAME TO %s', $this->quoteIdentifier($from), $this->quoteIdentifier($to));
    }

    /**
     * Vrai si une migration peut entourer son DDL d'une transaction, et l'annuler entière en cas
     * d'échec. MySQL et MariaDB valident chaque DDL implicitement (RG-06.6) ; SQLite reconstruit
     * ses tables hors transaction englobante (RG-01.11).
     */
    public function supportsTransactionalDdl(): bool
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
     * INSERT d'une ligne entièrement faite de valeurs par défaut (une table dont seul
     * l'identifiant auto-incrémenté est une colonne).
     */
    public function getInsertDefaultValuesSql(string $table): string
    {
        return 'INSERT INTO ' . $this->quoteIdentifier($table) . ' DEFAULT VALUES';
    }

    /**
     * Requête qui renvoie la prochaine valeur d'une séquence (une ligne, une colonne).
     *
     * @throws InvalidSchemaException plateforme sans séquences
     */
    public function getSequenceNextValSql(string $sequence): string
    {
        throw InvalidSchemaException::unsupportedFeature($this->getName(), 'sequences');
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
     * La requête la plus légère qui prouve que la connexion répond (Connection::ping()).
     */
    public function getPingSql(): string
    {
        return 'SELECT 1';
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
     * Étape 5 de RG-01.10 pour une table : clé primaire retirée, colonnes supprimées, ajoutées,
     * modifiées, puis nouvelle clé primaire.
     *
     * @return list<string>
     *
     * @throws InvalidSchemaException
     */
    protected function getAlterColumnsSql(TableDiff $diff): array
    {
        $sql = [];
        if ($diff->primaryKeyChanged() && null !== $diff->fromPrimaryKey) {
            $sql[] = $this->getDropPrimaryKeySql($diff->from);
        }
        foreach ($diff->droppedColumns as $column) {
            $sql[] = $this->getDropColumnSql($diff->name, $column->name);
        }
        foreach ($diff->addedColumns as $column) {
            $sql[] = $this->getAddColumnSql($diff->to, $column);
        }
        foreach ($diff->changedColumns as $column) {
            array_push($sql, ...$this->getChangeColumnSql($diff->to, $column));
        }
        if ($diff->primaryKeyChanged() && null !== $diff->toPrimaryKey) {
            $sql[] = $this->getAddPrimaryKeySql($diff->name, $diff->toPrimaryKey);
        }

        return $sql;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidSchemaException
     */
    abstract protected function getChangeColumnSql(Table $table, ColumnDiff $diff): array;

    abstract protected function getDropPrimaryKeySql(Table $table): string;

    /**
     * Suppression d'une clé étrangère d'une table conservée (MySQL y ajoute son index implicite).
     *
     * @return list<string>
     */
    protected function getDropForeignKeyStatements(TableDiff $table, ForeignKey $foreignKey): array
    {
        return [$this->getDropForeignKeySql($table->name, $foreignKey->name)];
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
