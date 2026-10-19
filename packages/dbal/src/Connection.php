<?php

declare(strict_types=1);

namespace Prism\Dbal;

use Prism\Dbal\Exception\AbortedTransactionException;
use Prism\Dbal\Exception\ConnectionFailedException;
use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\Exception\InvalidParameterException;
use Prism\Dbal\Exception\NoActiveTransactionException;
use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\Exception\UnknownTypeException;
use Prism\Dbal\Exception\UnsupportedPlatformException;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\PlatformResolver;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Dbal\Schema\SchemaManager;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Psr\Log\LoggerInterface;

/**
 * Connexion à une base, ouverte paresseusement à la première requête.
 *
 * Transactions imbriquées : le niveau 1 est une vraie transaction, les niveaux suivants des
 * savepoints. Annuler un niveau n'annule que lui ; l'appelant décide de propager (RG-01.1).
 *
 * @api
 */
final class Connection
{
    private ?\PDO $pdo = null;

    private int $nestingLevel = 0;

    /** PostgreSQL : niveau le plus bas auquel une requête a échoué dans la transaction en cours. */
    private ?int $abortedAtLevel = null;

    private readonly LoggerInterface $logger;

    private ?ConversionContext $conversionContext = null;

    /**
     * @internal utiliser DriverManager::fromUrl()
     */
    public function __construct(
        private readonly ConnectionParameters $parameters,
        private readonly Configuration $configuration,
        private ?AbstractPlatform $platform = null,
    ) {
        $this->logger = $configuration->logger;
    }

    /**
     * Sans serverVersion dans l'URL, ouvre la connexion pour lire la version du serveur.
     *
     * @throws ConnectionFailedException
     * @throws UnsupportedPlatformException version détectée sous le minimum supporté
     */
    public function getPlatform(): AbstractPlatform
    {
        if (null === $this->platform) {
            $this->connect();
        }

        return $this->platform ?? throw new \LogicException('The platform is resolved by connect().');
    }

    public function getDatabase(): ?string
    {
        return $this->parameters->database;
    }

    public function isConnected(): bool
    {
        return null !== $this->pdo;
    }

    /**
     * Ouvre la connexion si elle ne l'est pas déjà ; inutile en temps normal, toute requête le fait.
     *
     * @throws ConnectionFailedException
     * @throws UnsupportedPlatformException version détectée sous le minimum supporté
     */
    public function connect(): void
    {
        if (null !== $this->pdo) {
            return;
        }

        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
        if (Driver::MySql === $this->parameters->driver) {
            // vraies requêtes préparées (entiers natifs) et rowCount() = lignes trouvées, comme ailleurs
            $options[\PDO::ATTR_EMULATE_PREPARES] = false;
            $options[\Pdo\Mysql::ATTR_FOUND_ROWS] = true;
        }

        try {
            $pdo = \PDO::connect($this->parameters->getDsn(), $this->parameters->user, $this->parameters->getPassword(), $options);
        } catch (\PDOException $e) {
            $this->logger->error('Connection to {target} failed: {error}', ['target' => $this->parameters->describe(), 'error' => $e->getMessage()]);

            throw ConnectionFailedException::fromPdo($e, $this->parameters->describe());
        }

        if (null === $this->platform) {
            $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $this->platform = PlatformResolver::resolve($this->parameters, \is_string($version) ? $version : '');
        }
        $platform = $this->platform;

        $this->pdo = $pdo;
        foreach ($platform->getSessionInitSql() as $sql) {
            $this->exec($sql);
        }

        $this->logger->debug('Connected to {target} ({platform} {version})', [
            'target' => $this->parameters->describe(),
            'platform' => $platform->getName(),
            'version' => $platform->version,
        ]);
    }

    /**
     * Ferme la connexion ; une transaction en cours est annulée par le serveur.
     */
    public function close(): void
    {
        if ($this->nestingLevel > 0) {
            $this->logger->warning('Connection closed with an active transaction (level {level}); the server rolls it back.', ['level' => $this->nestingLevel]);
        }

        $this->pdo = null;
        $this->nestingLevel = 0;
        $this->abortedAtLevel = null;
    }

    /**
     * @param list<mixed>|array<string, mixed>        $params valeurs liées, jamais concaténées (RG-01.4)
     * @param array<int|string, ParameterType|string> $types  type par clé : ParameterType, ou nom d'un Type
     *                                                        (la valeur est alors convertie) ; déduit sinon
     *
     * @throws ConnectionFailedException
     * @throws InvalidParameterException valeur non scalaire, clés positionnelles et nommées mélangées
     * @throws UnknownTypeException      nom de type absent du registre
     * @throws ConversionException       valeur refusée par le Type
     * @throws QueryFailedException
     */
    public function executeQuery(string $sql, array $params = [], array $types = []): Result
    {
        return new Result($this->run($sql, $params, $types));
    }

    /**
     * @param list<mixed>|array<string, mixed>        $params
     * @param array<int|string, ParameterType|string> $types
     *
     * @return int nombre de lignes concernées
     *
     * @throws ConnectionFailedException
     * @throws InvalidParameterException
     * @throws UnknownTypeException
     * @throws ConversionException
     * @throws QueryFailedException
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): int
    {
        return $this->run($sql, $params, $types)->rowCount();
    }

    /**
     * @throws ConnectionFailedException
     * @throws QueryFailedException
     */
    public function beginTransaction(): void
    {
        $pdo = $this->pdo();
        $level = $this->nestingLevel + 1;

        if (1 === $level) {
            $this->logger->debug('Beginning transaction');
            $this->wrap('BEGIN', static fn (): bool => $pdo->beginTransaction());
        } else {
            $this->exec($this->getPlatform()->getCreateSavepointSql(self::savepoint($level)), ['level' => $level]);
        }

        $this->nestingLevel = $level;
    }

    /**
     * @throws NoActiveTransactionException sans transaction active (RG-01.2)
     * @throws AbortedTransactionException  PostgreSQL : une requête a invalidé la transaction
     * @throws QueryFailedException
     */
    public function commit(): void
    {
        if (0 === $this->nestingLevel) {
            throw NoActiveTransactionException::forOperation('commit');
        }

        if ($this->nestingLevel > 1) {
            $this->exec($this->getPlatform()->getReleaseSavepointSql(self::savepoint($this->nestingLevel)), ['level' => $this->nestingLevel]);
            --$this->nestingLevel;

            return;
        }

        $pdo = $this->pdo();

        if (null !== $this->abortedAtLevel) {
            // le serveur transformerait le COMMIT en ROLLBACK sans erreur : on le dit
            $this->rollBack();

            throw AbortedTransactionException::onCommit();
        }

        if (!$pdo->inTransaction()) {
            // MySQL / MariaDB : un DDL a déjà validé la transaction côté serveur
            $this->logger->warning('Transaction already committed implicitly by the server (DDL statement on MySQL or MariaDB).');
            $this->nestingLevel = 0;

            return;
        }

        $this->logger->debug('Committing transaction');
        try {
            $pdo->commit();
        } catch (\PDOException $e) {
            // relu sur la connexion : le COMMIT a pu clore la transaction avant d'échouer
            if (!$this->pdo()->inTransaction()) {
                $this->nestingLevel = 0;
            }
            $this->failed('COMMIT', $e, []);

            throw QueryFailedException::fromPdo($e, 'COMMIT');
        }

        $this->nestingLevel = 0;
    }

    /**
     * @throws NoActiveTransactionException sans transaction active (RG-01.2), ou si le serveur
     *                                      l'a déjà validée implicitement (DDL sous MySQL / MariaDB)
     * @throws QueryFailedException
     */
    public function rollBack(): void
    {
        if (0 === $this->nestingLevel) {
            throw NoActiveTransactionException::forOperation('roll back');
        }

        if (null !== $this->abortedAtLevel && $this->abortedAtLevel >= $this->nestingLevel) {
            $this->abortedAtLevel = null;
        }

        if ($this->nestingLevel > 1) {
            $this->exec($this->getPlatform()->getRollbackSavepointSql(self::savepoint($this->nestingLevel)), ['level' => $this->nestingLevel]);
            --$this->nestingLevel;

            return;
        }

        $pdo = $this->pdo();
        $this->nestingLevel = 0;
        $this->abortedAtLevel = null;

        if (!$pdo->inTransaction()) {
            $this->logger->error('Cannot roll back: the transaction was already committed implicitly by the server.');

            throw NoActiveTransactionException::implicitlyCommitted();
        }

        $this->logger->debug('Rolling back transaction');
        $this->wrap('ROLLBACK', static fn (): bool => $pdo->rollBack());
    }

    /**
     * Exécute $callback dans une transaction (ou un savepoint si une transaction est déjà active) :
     * commit si le callback se termine, rollback de ce niveau puis exception relancée sinon.
     *
     * @template T
     *
     * @param \Closure(self): T $callback
     *
     * @return T
     */
    public function transactional(\Closure $callback): mixed
    {
        $this->beginTransaction();
        $level = $this->nestingLevel;

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->nestingLevel >= $level) {
                try {
                    $this->rollBack();
                } catch (\Throwable $rollbackError) {
                    $this->logger->error('Rollback after a failed transactional() callback failed: {error}', ['error' => $rollbackError->getMessage(), 'exception' => $rollbackError]);
                }
            }

            throw $e;
        }
    }

    /**
     * Identifiant produit par le dernier INSERT d'une colonne IDENTITY / AUTO_INCREMENT de cette
     * connexion (PostgreSQL : lastval()).
     *
     * @throws QueryFailedException aucun identifiant généré dans la session (PostgreSQL)
     */
    public function lastInsertId(): string
    {
        $pdo = $this->pdo();

        try {
            $id = $pdo->lastInsertId();
        } catch (\PDOException $e) {
            $this->failed('lastInsertId()', $e, []);

            throw QueryFailedException::fromPdo($e, 'lastInsertId()');
        }

        return false === $id ? '' : $id;
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    public function createSchemaManager(): SchemaManager
    {
        return new SchemaManager($this);
    }

    public function getTypeRegistry(): TypeRegistry
    {
        return $this->configuration->types;
    }

    /**
     * Contexte de conversion des types : plateforme (ouvre la connexion sans serverVersion) et
     * fuseau de stockage.
     */
    public function getConversionContext(): ConversionContext
    {
        return $this->conversionContext ??= new ConversionContext($this->getPlatform(), $this->configuration->timezone);
    }

    /**
     * Convertit une valeur lue en base selon un type du registre (decimal → string, json → array…).
     *
     * @throws UnknownTypeException
     * @throws ConversionException
     */
    public function convertToPhp(mixed $value, string $type): mixed
    {
        return $this->configuration->types->get($type)->toPhp($value, $this->getConversionContext());
    }

    /**
     * @return scalar|null
     *
     * @throws UnknownTypeException
     * @throws ConversionException
     */
    public function convertToDatabase(mixed $value, string $type): mixed
    {
        return $this->configuration->types->get($type)->toDatabase($value, $this->getConversionContext());
    }

    public function getTransactionNestingLevel(): int
    {
        return $this->nestingLevel;
    }

    public function isTransactionActive(): bool
    {
        return $this->nestingLevel > 0;
    }

    /**
     * @param list<mixed>|array<string, mixed>        $params
     * @param array<int|string, ParameterType|string> $types
     */
    private function run(string $sql, array $params, array $types): \PDOStatement
    {
        $pdo = $this->pdo();
        $bound = $this->bindings($params, $types);
        $start = hrtime(true);

        try {
            $statement = $pdo->prepare($sql);
            foreach ($bound as [$name, $value, $type]) {
                $statement->bindValue($name, $value, $type->toPdo());
            }
            $statement->execute();
        } catch (\PDOException $e) {
            $this->failed($sql, $e, $bound);

            throw QueryFailedException::fromPdo($e, $sql);
        }

        $this->logger->debug('Query executed in {duration_ms} ms: {sql}', $this->context($sql, $bound) + ['duration_ms' => self::elapsed($start)]);

        return $statement;
    }

    /**
     * SQL sans paramètre (transactions, initialisation de session).
     *
     * @param array<string, mixed> $context
     */
    private function exec(string $sql, array $context = []): void
    {
        $pdo = $this->pdo();
        $start = hrtime(true);

        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            $this->failed($sql, $e, []);

            throw QueryFailedException::fromPdo($e, $sql);
        }

        $this->logger->debug('Query executed in {duration_ms} ms: {sql}', ['sql' => $sql, 'duration_ms' => self::elapsed($start)] + $context);
    }

    /**
     * Appel PDO de transaction (BEGIN, COMMIT, ROLLBACK) avec erreurs converties.
     *
     * @param \Closure(): bool $call
     */
    private function wrap(string $sql, \Closure $call): void
    {
        try {
            $call();
        } catch (\PDOException $e) {
            $this->failed($sql, $e, []);

            throw QueryFailedException::fromPdo($e, $sql);
        }
    }

    /**
     * @param list<array{int|string, mixed, ParameterType}> $bound
     */
    private function failed(string $sql, \PDOException $e, array $bound): void
    {
        if ($this->nestingLevel > 0 && null === $this->abortedAtLevel && $this->getPlatform()->failedStatementAbortsTransaction()) {
            $this->abortedAtLevel = $this->nestingLevel;
        }

        $this->logger->error('Query failed: {error} ({sql})', $this->context($sql, $bound) + ['error' => $e->getMessage(), 'exception' => $e]);
    }

    /**
     * @param list<mixed>|array<string, mixed>        $params
     * @param array<int|string, ParameterType|string> $types
     *
     * @return list<array{int|string, mixed, ParameterType}> nom PDO, valeur, type
     */
    private function bindings(array $params, array $types): array
    {
        if ([] === $params) {
            return [];
        }

        $positional = array_is_list($params);
        $bound = [];

        foreach ($params as $key => $value) {
            if (\is_int($key) !== $positional) {
                throw InvalidParameterException::mixedKeys();
            }

            $type = $types[$key] ?? null;
            if (\is_string($type)) {
                $converter = $this->configuration->types->get($type);
                $value = $converter->toDatabase($value, $this->getConversionContext());
                $type = null === $value ? ParameterType::Null : $converter->getBindingType();
            }

            $type ??= ParameterType::infer($value) ?? throw InvalidParameterException::unsupportedValue($key, $value);
            if (null !== $value && !\is_scalar($value)) {
                throw InvalidParameterException::unsupportedValue($key, $value);
            }

            $name = \is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $bound[] = [$name, $value, $type];
        }

        return $bound;
    }

    /**
     * @param list<array{int|string, mixed, ParameterType}> $bound
     *
     * @return array<string, mixed>
     */
    private function context(string $sql, array $bound): array
    {
        $context = ['sql' => $sql];
        if ([] === $bound) {
            return $context;
        }

        $context['types'] = array_map(static fn (array $b): string => $b[2]->name, $bound);
        if ($this->configuration->logParameters) {
            $context['params'] = array_map(static fn (array $b): mixed => $b[1], $bound);
        }

        return $context;
    }

    private function pdo(): \PDO
    {
        $this->connect();

        return $this->pdo ?? throw new \LogicException('connect() opens the PDO connection.');
    }

    private static function savepoint(int $level): string
    {
        return 'PRISM_SAVEPOINT_' . $level;
    }

    private static function elapsed(int $start): float
    {
        return round((hrtime(true) - $start) / 1e6, 3);
    }
}
