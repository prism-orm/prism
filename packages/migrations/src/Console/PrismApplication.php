<?php

declare(strict_types=1);

namespace Prism\Migrations\Console;

use Prism\Migrations\Console\Command as Migrations;
use Prism\Orm\Console\Command as Orm;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Component\Console\Application;

/**
 * La console de bin/prism, hors Symfony : toutes les commandes db:*, câblées depuis le prism.php
 * du projet, qui retourne l'EntityManager ou un PrismConfig.
 *
 * @api
 */
final class PrismApplication
{
    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException fichier absent, ou qui ne retourne ni EntityManager ni PrismConfig
     */
    public static function load(string $file): PrismConfig
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException(\sprintf('No %s: create it so that it returns your EntityManager, or a Prism\Migrations\Console\PrismConfig; or pass --config=<file>.', $file));
        }
        $config = (static fn (string $file): mixed => require $file)($file);

        return match (true) {
            $config instanceof PrismConfig => $config,
            $config instanceof EntityManagerInterface => new PrismConfig($config),
            default => throw new \InvalidArgumentException(\sprintf('%s returns %s: it must return an EntityManagerInterface or a Prism\Migrations\Console\PrismConfig.', $file, get_debug_type($config))),
        };
    }

    /**
     * @throws \InvalidArgumentException EntityManager sans MetadataFactory accessible
     */
    public static function create(PrismConfig $config): Application
    {
        $em = $config->entityManager;
        $connection = $em->getConnection();
        $metadata = $config->metadata ?? ($em instanceof EntityManager ? $em->getMetadataFactory() : throw new \InvalidArgumentException(\sprintf('%s does not expose its MetadataFactory: give it to PrismConfig(metadata: ...).', $em::class)));
        $migrations = $config->migrations;

        $application = new Application('Prism', self::version());
        $application->addCommands([
            new Orm\DatabaseCreateCommand($connection),
            new Orm\DatabaseDropCommand($connection, $migrations->environment),
            new Orm\SchemaCreateCommand($connection, $metadata),
            new Orm\SchemaUpdateCommand($connection, $metadata),
            new Orm\SchemaDropCommand($connection, $metadata),
            new Orm\SchemaValidateCommand($connection, $metadata),
            new Orm\MappingInfoCommand($metadata),
            new Orm\QuerySqlCommand($connection),
            new Orm\CacheClearMetadataCommand($metadata),
            new Orm\MetamodelGenerateCommand($metadata, $config->metamodelDir),
            new Migrations\GenerateCommand($migrations),
            new Migrations\DiffCommand($connection, $metadata, $migrations),
            new Migrations\MigrateCommand($connection, $migrations, $em),
            new Migrations\ExecuteCommand($connection, $migrations, $em),
            new Migrations\StatusCommand($connection, $migrations),
            new Migrations\ListCommand($connection, $migrations),
            new Migrations\LintCommand($connection, $migrations, $metadata, $config->largeTable),
        ]);

        return $application;
    }

    /**
     * Retire --config=<fichier> (ou --config <fichier>) des arguments, avant que la console ne
     * les lise : il faut le fichier pour créer les commandes.
     *
     * @param list<string> $argv
     *
     * @return array{string, list<string>} le fichier, et les arguments restants
     */
    public static function configFile(array $argv, string $default): array
    {
        $rest = [];
        $file = $default;
        for ($i = 0, $n = \count($argv); $i < $n; ++$i) {
            if (str_starts_with($argv[$i], '--config=')) {
                $file = substr($argv[$i], \strlen('--config='));
            } elseif ('--config' === $argv[$i] && isset($argv[$i + 1])) {
                $file = $argv[++$i];
            } else {
                $rest[] = $argv[$i];
            }
        }

        return [$file, $rest];
    }

    private static function version(): string
    {
        $version = class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('prism-orm/migrations')
            ? \Composer\InstalledVersions::getPrettyVersion('prism-orm/migrations')
            : null;

        return $version ?? 'dev';
    }
}
