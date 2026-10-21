<?php

declare(strict_types=1);

namespace Prism\Migrations\Console;

use Prism\Migrations\MigrationsConfiguration;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Metadata\MetadataFactory;

/**
 * Ce que retourne prism.php pour bin/prism, l'usage hors Symfony. Retourner l'EntityManager
 * seul suffit : les migrations prennent alors leurs valeurs par défaut.
 *
 *     // prism.php
 *     return new PrismConfig(
 *         entityManager: new EntityManager(DriverManager::fromUrl($_ENV['DATABASE_URL'])),
 *         migrations: new MigrationsConfiguration('migrations', 'App\Migrations', 'dev'),
 *     );
 *
 * @api
 */
final class PrismConfig
{
    public readonly MigrationsConfiguration $migrations;

    /**
     * @param MetadataFactory|null $metadata   celle de l'EntityManager par défaut ; à donner pour
     *                                         une autre implémentation d'EntityManagerInterface
     * @param int                  $largeTable seuil d'une grande table pour le linter (L10)
     */
    public function __construct(
        public readonly EntityManagerInterface $entityManager,
        ?MigrationsConfiguration $migrations = null,
        public readonly ?MetadataFactory $metadata = null,
        public readonly string $metamodelDir = 'var/cache/prism/metamodel',
        public readonly int $largeTable = 1_000_000,
    ) {
        $this->migrations = $migrations ?? new MigrationsConfiguration();
    }
}
