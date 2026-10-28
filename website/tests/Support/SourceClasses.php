<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Support;

/**
 * Les classes déclarées dans un dossier de sources, lues sans les charger.
 *
 * @internal
 */
final class SourceClasses
{
    /**
     * @return array<string, string> classe → source du fichier, une classe par fichier (PSR-4)
     */
    public static function in(string $directory): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (1 === preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $namespace)
                && 1 === preg_match('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $name)) {
                $classes[$namespace[1] . '\\' . $name[1]] = $source;
            }
        }
        ksort($classes);

        return $classes;
    }

    /**
     * Les classes @api des paquets (packages/<paquet>/src) : l'API publique que la référence couvre.
     *
     * @return array<class-string, string> classe → source
     */
    public static function api(): array
    {
        $api = [];
        $sources = glob(DocPages::root() . '/packages/*/src', \GLOB_ONLYDIR);
        foreach (false === $sources ? [] : $sources as $src) {
            foreach (self::in($src) as $class => $source) {
                if ('api' === self::tag($source) && (class_exists($class) || interface_exists($class) || enum_exists($class))) {
                    $api[$class] = $source;
                }
            }
        }
        ksort($api);

        return $api;
    }

    /**
     * Le marquage de la classe, lu dans le docblock qui précède sa déclaration (ADR 0020) : « api »,
     * « internal », ou null sans l'un ni l'autre.
     */
    public static function tag(string $source): ?string
    {
        if (1 !== preg_match('/(\/\*\*(?:(?!\*\/).)*\*\/)\s*(?:#\[[^\n]*\]\s*)*^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s/ms', $source, $match)) {
            return null;
        }

        return match (true) {
            1 === preg_match('/^\s*\* @internal\b/m', $match[1]) => 'internal',
            1 === preg_match('/^\s*\* @api\b/m', $match[1]) => 'api',
            default => null,
        };
    }

    public static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
