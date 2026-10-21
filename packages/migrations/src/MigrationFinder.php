<?php

declare(strict_types=1);

namespace Prism\Migrations;

use Prism\Migrations\Exception\MigrationException;

/**
 * Les migrations d'un dossier : un fichier VersionYYYYMMDDHHMMSS.php par migration, dans le
 * namespace donné, triées par version, donc dans l'ordre chronologique (RG-06.1).
 *
 * @api
 */
final class MigrationFinder
{
    public function __construct(
        public readonly string $directory,
        public readonly string $namespace,
    ) {
    }

    /**
     * @return array<string, AbstractMigration> par version, chronologique
     *
     * @throws MigrationException fichier mal nommé, classe absente ou qui n'est pas une migration
     */
    public function find(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $migrations = [];
        $files = glob(rtrim($this->directory, '/\\') . '/*.php');
        foreach (false === $files ? [] : $files as $file) {
            $version = basename($file, '.php');
            if (1 !== preg_match('/^Version\d{14}$/', $version)) {
                throw MigrationException::invalidFileName($file);
            }
            $class = trim($this->namespace, '\\') . '\\' . $version;
            if (!class_exists($class, false)) {
                require_once $file;
            }
            if (!class_exists($class, false)) {
                throw MigrationException::classNotFound($file, $class);
            }
            $migration = new $class();
            if (!$migration instanceof AbstractMigration) {
                throw MigrationException::notAMigration($class);
            }
            $migrations[$version] = $migration;
        }
        ksort($migrations, \SORT_STRING);

        return $migrations;
    }
}
