<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata\Driver;

/**
 * Trouve les classes déclarées dans des dossiers en lisant les fichiers par tokens, sans les
 * exécuter : un fichier qui n'est pas une classe (script, configuration) n'a aucun effet.
 *
 * @internal
 */
final class ClassLocator
{
    /**
     * @param list<string> $directories
     *
     * @return list<string> noms de classes complets, triés
     */
    public static function find(array $directories): array
    {
        $classes = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && 'php' === $file->getExtension()) {
                    array_push($classes, ...self::classesIn((string) file_get_contents($file->getPathname())));
                }
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function classesIn(string $code): array
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize($code), static fn (\PhpToken $t): bool => !$t->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])));
        $namespace = '';
        $classes = [];

        foreach ($tokens as $i => $token) {
            if ($token->is(\T_NAMESPACE) && isset($tokens[$i + 1]) && $tokens[$i + 1]->is([\T_NAME_QUALIFIED, \T_STRING])) {
                $namespace = $tokens[$i + 1]->text . '\\';
            }
            // « class » d'une déclaration, pas « Foo::class » ni « new class »
            if ($token->is(\T_CLASS) && isset($tokens[$i + 1]) && $tokens[$i + 1]->is(\T_STRING)
                && !(isset($tokens[$i - 1]) && $tokens[$i - 1]->is([\T_DOUBLE_COLON, \T_NEW]))) {
                $classes[] = $namespace . $tokens[$i + 1]->text;
            }
        }

        return $classes;
    }
}
