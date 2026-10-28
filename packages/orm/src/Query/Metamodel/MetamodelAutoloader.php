<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

/**
 * Charge les classes du metamodel (nom finissant par « _ ») depuis leur dossier de génération
 * (ADR 0009). Le bundle l'enregistre ; hors Symfony, l'application l'appelle une fois.
 *
 * @internal
 */
final class MetamodelAutoloader
{
    /** @var array<string, true> dossiers déjà enregistrés */
    private static array $registered = [];

    public static function register(string $dir): void
    {
        $dir = rtrim($dir, '/\\');
        if (isset(self::$registered[$dir])) {
            return;
        }
        self::$registered[$dir] = true;

        spl_autoload_register(static function (string $class) use ($dir): void {
            if (!str_ends_with($class, '_')) {
                return;
            }
            $file = $dir . '/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
