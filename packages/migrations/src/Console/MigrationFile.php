<?php

declare(strict_types=1);

namespace Prism\Migrations\Console;

use Prism\Migrations\MigrationsConfiguration;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le fichier d'une nouvelle migration, partagé par generate et diff : nommé d'après l'heure
 * UTC (RG-06.1), jamais écrit par-dessus un fichier existant.
 *
 * @internal
 */
final class MigrationFile
{
    public static function version(\DateTimeImmutable $now): string
    {
        return 'Version' . $now->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis');
    }

    /**
     * @return string|null le chemin écrit ; null si refusé, l'erreur affichée
     */
    public static function write(SymfonyStyle $io, MigrationsConfiguration $configuration, string $version, string $content): ?string
    {
        $directory = rtrim($configuration->directory, '/\\');
        $file = $directory . '/' . $version . '.php';
        if (is_file($file)) {
            $io->error(\sprintf('%s already exists: wait a second and run the command again.', $file));

            return null;
        }
        if ((!is_dir($directory) && !@mkdir($directory, 0o777, true)) || false === @file_put_contents($file, $content)) {
            $io->error(\sprintf('Cannot write %s: check the permissions of %s.', $file, $directory));

            return null;
        }

        return $file;
    }
}
