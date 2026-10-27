<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Support;

/**
 * Les pages du site (ADR 0016) : website/ hors node_modules et .vitepress, et packages/<paquet>/docs ;
 * les mêmes que srcDir et srcExclude de website/.vitepress/config.mts.
 *
 * @internal
 */
final class DocPages
{
    public static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return list<string> chemins depuis la racine du monorepo, triés
     */
    public static function files(): array
    {
        $root = self::root();
        $files = [];
        $packages = glob($root . '/packages/*/docs', \GLOB_ONLYDIR);
        $directories = [$root . '/website', ...(false === $packages ? [] : $packages)];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !\in_array($file->getFilename(), ['node_modules', '.vitepress', 'tests', 'public'], true),
            ));
            foreach ($iterator as $file) {
                \assert($file instanceof \SplFileInfo);
                if ('md' === $file->getExtension()) {
                    $files[] = substr($file->getPathname(), \strlen($root) + 1);
                }
            }
        }
        sort($files);

        return $files;
    }

    public static function read(string $file): string
    {
        $content = file_get_contents(self::root() . '/' . $file);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read %s.', $file));
        }

        return $content;
    }

    public static function exists(string $file): bool
    {
        return is_file(self::root() . '/' . $file);
    }

    /**
     * « examples: sequence » dans le frontmatter : les blocs PHP de la page s'exécutent à la suite,
     * dans un même processus (un guide pas à pas), au lieu d'un processus par bloc.
     */
    public static function isSequence(string $file): bool
    {
        return 1 === preg_match('/\A---\n(?:.*\n)*?examples:\s*sequence\s*\n(?:.*\n)*?---\n/', self::read($file));
    }

    /**
     * Les blocs d'un langage dans toutes les pages, dans l'ordre des fichiers puis des lignes.
     *
     * @return list<CodeBlock>
     */
    public static function codeBlocks(string $language): array
    {
        $blocks = [];
        foreach (self::files() as $file) {
            foreach (self::parse($file, self::read($file)) as $block) {
                if ($language === $block->language) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * Clôtures ``` ou ~~~ (indentées de 3 espaces au plus) ; info « php:line-numbers no-run [Onglet] {2,4} ».
     *
     * @return list<CodeBlock>
     */
    public static function parse(string $file, string $markdown): array
    {
        $blocks = [];
        $open = null;
        $lines = [];
        foreach (explode("\n", $markdown) as $index => $line) {
            if (null === $open) {
                if (1 === preg_match('/^ {0,3}(`{3,}|~{3,})\s*([^`]*)$/', $line, $m)) {
                    $open = ['fence' => $m[1], 'info' => trim($m[2]), 'line' => $index + 1];
                    $lines = [];
                }
                continue;
            }
            if (1 === preg_match('/^ {0,3}' . preg_quote($open['fence'][0], '/') . '{' . \strlen($open['fence']) . ',}\s*$/', $line)) {
                // les titres d'onglet [..] et les lignes en surbrillance {..} de VitePress ne sont pas des marqueurs
                $info = trim((string) preg_replace('/\[[^\]]*\]|\{[^}]*\}/', '', $open['info']));
                $words = '' === $info ? [] : preg_split('/\s+/', $info);
                $words = false === $words ? [] : $words;
                $language = strtolower(explode(':', array_shift($words) ?? '')[0]);
                $blocks[] = new CodeBlock($file, $open['line'], $language, $words, implode("\n", $lines));
                $open = null;
                continue;
            }
            $lines[] = $line;
        }

        return $blocks;
    }
}
