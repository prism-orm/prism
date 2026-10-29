<?php

declare(strict_types=1);

namespace Prism\Migrations;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\SchemaDiff;
use Prism\Dbal\Schema\SchemaManager;
use Prism\Migrations\Exception\IrreversibleMigrationException;
use Prism\Migrations\Exception\MigrationException;
use Prism\Migrations\Exception\MigrationsException;
use Prism\Orm\EntityManagerInterface;

/**
 * Exécute les migrations (ADR 0012) : dans l'ordre chronologique (RG-06.1), après avoir vérifié
 * que les migrations exécutées n'ont pas changé (RG-06.4). Une migration tourne dans une
 * transaction quand la plateforme sait annuler son DDL ; sinon elle tourne sans, et le compte
 * rendu le signale (RG-06.6).
 *
 * @internal
 */
final class Migrator
{
    private readonly VersionStorage $storage;

    private readonly SchemaManager $schemaManager;

    /**
     * @param EntityManagerInterface|null $entityManager pour les migrations de données (RG-06.8),
     *                                                   sur la même connexion
     *
     * @throws MigrationException EntityManager sur une autre connexion
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationFinder $finder,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
        if (null !== $entityManager && $entityManager->getConnection() !== $connection) {
            throw MigrationException::entityManagerOnAnotherConnection();
        }
        $this->storage = new VersionStorage($connection);
        $this->schemaManager = new SchemaManager($connection);
    }

    /**
     * Toutes les migrations connues, fichiers et versions exécutées, par version.
     *
     * @return list<MigrationStatus>
     *
     * @throws MigrationException
     */
    public function status(): array
    {
        $available = $this->finder->find();
        $executed = $this->storage->executed();
        $versions = array_unique([...array_keys($available), ...array_keys($executed)]);
        sort($versions, \SORT_STRING);

        $status = [];
        foreach ($versions as $version) {
            $migration = $available[$version] ?? null;
            $run = $executed[$version] ?? null;
            $state = match (true) {
                null === $run => MigrationState::Pending,
                null === $migration => MigrationState::Missing,
                $this->checksum($migration) !== $run['checksum'] => MigrationState::Modified,
                default => MigrationState::Executed,
            };
            $status[] = new MigrationStatus($version, $state, $migration?->getDescription() ?? '', $run['executedAt'] ?? null, $run['executionTimeMs'] ?? null);
        }

        return $status;
    }

    /**
     * Migre jusqu'à une version : les migrations en attente jusqu'à elle, puis celles exécutées
     * après elle, revertées de la plus récente à la plus ancienne. Sans version : toutes les
     * migrations en attente ; « 0 » : tout revertir. Une version comprend sa migration de données
     * (VersionXData), qui suit sa migration de schéma (RG-06.8).
     *
     * @throws MigrationException             version inconnue, migration modifiée (RG-06.4) ou échouée
     * @throws IrreversibleMigrationException migration sans down() à revertir (RG-06.7)
     */
    public function migrate(?string $to = null, bool $dryRun = false, bool $allowModified = false): MigrationResult
    {
        $available = $this->finder->find();
        $executed = $this->storage->executed();
        $warnings = [];
        foreach ($executed as $version => $run) {
            if (!isset($available[$version])) {
                $warnings[] = \sprintf('The migration %s is recorded as executed, but its file is missing.', $version);
            } elseif (!$allowModified && $this->checksum($available[$version]) !== $run['checksum']) {
                throw MigrationException::modified($version);
            }
        }
        if (null !== $to && '0' !== $to && !isset($available[$to])) {
            throw MigrationException::unknownVersion($to);
        }

        $target = null === $to || '0' === $to ? $to : self::timestamp($to);
        $down = null === $target ? [] : array_reverse(array_filter(array_keys($executed), static fn (string $v): bool => '0' === $target || strcmp(self::timestamp($v), $target) > 0));
        $up = array_filter(array_keys($available), static fn (string $v): bool => !isset($executed[$v]) && (null === $target || ('0' !== $target && strcmp(self::timestamp($v), $target) <= 0)));
        foreach ($down as $version) {
            if (!isset($available[$version])) {
                throw MigrationException::unknownVersion($version);
            }
        }

        $run = [];
        $schema = $this->schema($dryRun);
        foreach ($down as $version) {
            [$run[], $schema] = $this->run($version, $available[$version], Direction::Down, $schema, $dryRun, $warnings);
        }
        foreach ($up as $version) {
            [$run[], $schema] = $this->run($version, $available[$version], Direction::Up, $schema, $dryRun, $warnings);
        }

        return new MigrationResult($run, $warnings, $dryRun);
    }

    /**
     * Une seule migration, dans un sens.
     *
     * @throws MigrationException             version inconnue, déjà exécutée ou pas exécutée, échec
     * @throws IrreversibleMigrationException --down d'une migration sans down() (RG-06.7)
     */
    public function execute(string $version, Direction $direction, bool $dryRun = false): MigrationResult
    {
        $migration = $this->finder->find()[$version] ?? throw MigrationException::unknownVersion($version);
        $executed = isset($this->storage->executed()[$version]);
        if (Direction::Up === $direction && $executed) {
            throw MigrationException::alreadyExecuted($version);
        }
        if (Direction::Down === $direction && !$executed) {
            throw MigrationException::notExecuted($version);
        }
        $warnings = [];
        [$run] = $this->run($version, $migration, $direction, $this->schema($dryRun), $dryRun, $warnings);

        return new MigrationResult([$run], $warnings, $dryRun);
    }

    // VersionYYYYMMDDHHMMSS et VersionYYYYMMDDHHMMSSData : le même horodatage
    private static function timestamp(string $version): string
    {
        return substr($version, 7, 14);
    }

    /**
     * Le checksum d'une migration : celui des opérations de son up() ; celui de son fichier pour
     * une migration de données (RG-06.4).
     */
    private function checksum(AbstractMigration|DataMigration $migration): string
    {
        if ($migration instanceof DataMigration) {
            return $migration->checksum();
        }
        $builder = new MigrationBuilder($this->connection->getPlatform());
        $migration->up($builder);

        return $builder->checksum();
    }

    private function schema(bool $dryRun): Schema
    {
        if (!$dryRun) {
            $this->storage->ensure();
        }

        return $this->schemaManager->introspect();
    }

    /**
     * @param list<string> $warnings
     *
     * @return array{ExecutedMigration, Schema} le compte rendu, et le schéma après la migration
     *
     * @throws MigrationException
     * @throws IrreversibleMigrationException
     */
    private function run(string $version, AbstractMigration|DataMigration $migration, Direction $direction, Schema $schema, bool $dryRun, array &$warnings): array
    {
        if ($migration instanceof DataMigration) {
            return [$this->runData($version, $migration, $direction, $dryRun), $schema];
        }
        $platform = $this->connection->getPlatform();
        $builder = new MigrationBuilder($platform);
        Direction::Up === $direction ? $migration->up($builder) : $migration->down($builder);
        try {
            $plan = new Planner($platform)->plan($schema, $builder->operations());
        } catch (InvalidSchemaException $e) {
            throw MigrationException::invalidOperation($version, $e);
        }
        $sql = $plan->sql($platform);
        $transactional = $migration->isTransactional() && !$plan->outsideTransaction && ($platform->supportsTransactionalDdl() || !$plan->ddl);
        if ($dryRun) {
            return [new ExecutedMigration($version, $direction, $sql, $transactional), $plan->schema];
        }

        $start = hrtime(true);
        $milliseconds = 0;
        $work = function (Connection $connection) use ($plan, $version, $direction, $builder, $start, &$milliseconds): void {
            foreach ($plan->steps as $step) {
                if ($step instanceof SchemaDiff) {
                    $this->schemaManager->migrate($step);
                } else {
                    $connection->executeStatement($step[0], $step[1]);
                }
            }
            $milliseconds = intdiv(hrtime(true) - $start, 1_000_000);
            Direction::Up === $direction
                ? $this->storage->record($version, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $milliseconds, $builder->checksum())
                : $this->storage->remove($version);
        };
        try {
            $transactional ? $this->connection->transactional($work) : $work($this->connection);
        } catch (MigrationsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw MigrationException::failed($version, $direction, !$transactional, $e);
        }
        if (!$transactional && $plan->ddl) {
            // RG-06.6
            $warnings[] = \sprintf('The migration %s ran without a transaction (%s commits each DDL statement): a failure would have left a partial state.', $version, $platform->getName());
        }

        return [new ExecutedMigration($version, $direction, $sql, $transactional, $milliseconds), $this->schemaManager->introspect()];
    }

    /**
     * RG-06.8 : du code PHP avec l'EntityManager, toujours dans une transaction (le DML y est
     * transactionnel sur les 4 bases), la ligne de version comprise ; l'EntityManager est vidé
     * ensuite.
     *
     * @throws MigrationException
     * @throws IrreversibleMigrationException revert() absent (RG-06.7)
     */
    private function runData(string $version, DataMigration $migration, Direction $direction, bool $dryRun): ExecutedMigration
    {
        $em = $this->entityManager ?? throw MigrationException::entityManagerRequired($version);
        if (Direction::Down === $direction && !$migration->isReversible()) {
            throw IrreversibleMigrationException::forVersion($version, 'it has no revert()');
        }
        if ($dryRun) {
            return new ExecutedMigration($version, $direction, [], true, data: true);
        }

        $start = hrtime(true);
        $milliseconds = 0;
        try {
            $this->connection->transactional(function () use ($em, $migration, $direction, $version, $start, &$milliseconds): void {
                Direction::Up === $direction ? $migration->run($em) : $migration->revert($em);
                $em->flush();
                $milliseconds = intdiv(hrtime(true) - $start, 1_000_000);
                Direction::Up === $direction
                    ? $this->storage->record($version, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $milliseconds, $migration->checksum())
                    : $this->storage->remove($version);
            });
        } catch (MigrationsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw MigrationException::failed($version, $direction, false, $e);
        } finally {
            $em->clear();
        }

        return new ExecutedMigration($version, $direction, [], true, $milliseconds, data: true);
    }
}
