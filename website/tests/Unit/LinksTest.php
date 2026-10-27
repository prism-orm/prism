<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Website\Tests\Support\DocPages;

/**
 * Les liens internes des pages et leurs ancres. Le build de VitePress refuse une page absente,
 * pas une ancre absente : ce test calcule les ancres des titres comme VitePress (le slugify du
 * paquet mdit-vue/shared) et vérifie chaque lien « /page#ancre », « page.md#ancre » ou « #ancre ».
 */
final class LinksTest extends TestCase
{
    public function testEveryInternalLinkReachesAPageAndAHeading(): void
    {
        $broken = [];
        foreach (DocPages::files() as $file) {
            foreach (self::links($file) as [$line, $target]) {
                $parts = explode('#', $target, 2);
                $path = $parts[0];
                $anchor = $parts[1] ?? null;
                $page = '' === $path ? $file : self::source($file, $path);
                if (null === $page) {
                    $broken[] = \sprintf('%s:%d: %s: no such page', $file, $line, $target);
                } elseif (null !== $anchor && !\in_array($anchor, self::anchors($page), true)) {
                    $broken[] = \sprintf('%s:%d: %s: no heading "%s" in %s', $file, $line, $target, $anchor, $page);
                }
            }
        }

        self::assertSame([], $broken);
    }

    public function testSlugsFollowVitePress(): void
    {
        self::assertSame('the-n-1-detector', self::slug('The N+1 detector'));
        self::assertSame('entity', self::slug('`#[Entity]`'));
        self::assertSame('prism-connections-name-url', self::slug('`prism.connections.<name>.url`'));
        self::assertSame('no-tenant-no-query', self::slug('No tenant, no query'));
        self::assertSame('_1-install', self::slug('1. Install'));
    }

    /**
     * Les liens internes d'une page, hors blocs de code : [ligne, cible].
     *
     * @return list<array{int, string}>
     */
    private static function links(string $file): array
    {
        $links = [];
        $fence = null;
        foreach (explode("\n", DocPages::read($file)) as $index => $line) {
            if (1 === preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                $fence = null === $fence ? $m[1][0] : ($m[1][0] === $fence ? null : $fence);
                continue;
            }
            if (null !== $fence) {
                continue;
            }
            preg_match_all('/\]\(([^)\s]+)\)/', (string) preg_replace('/`[^`]*`/', '', $line), $matches);
            foreach ($matches[1] as $target) {
                if (1 !== preg_match('#^[a-z]+:#i', $target)) {
                    $links[] = [$index + 1, $target];
                }
            }
        }

        return $links;
    }

    /**
     * Le fichier source d'un lien : une URL du site (/guide/audit) ou un chemin relatif (commands.md).
     */
    private static function source(string $from, string $path): ?string
    {
        if (!str_starts_with($path, '/')) {
            $candidate = self::normalize(\dirname($from) . '/' . $path);

            return DocPages::exists($candidate) ? $candidate : null;
        }
        // les rewrites de website/.vitepress/config.mts, à l'envers
        $url = '/' === $path ? 'index' : trim($path, '/');
        $candidates = ['website/' . $url . '.md', 'website/' . $url . '/index.md'];
        if (str_starts_with($url, 'reference/')) {
            $references = glob(DocPages::root() . '/packages/*/docs/' . $url . '.md');
            $candidates = [...$candidates, ...(false === $references ? [] : $references)];
        } elseif (1 === preg_match('#^([a-z-]+)/(.+)$#', $url, $m)) {
            $candidates[] = 'packages/' . $m[1] . '/docs/' . $m[2] . '.md';
        }
        foreach ($candidates as $candidate) {
            $candidate = str_starts_with($candidate, DocPages::root()) ? substr($candidate, \strlen(DocPages::root()) + 1) : $candidate;
            if (DocPages::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('.' !== $part && '' !== $part) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * Les ancres des titres d'une page, doublons suffixés -1, -2 comme VitePress.
     *
     * @return list<string>
     */
    private static function anchors(string $file): array
    {
        $anchors = [];
        $fence = null;
        foreach (explode("\n", DocPages::read($file)) as $line) {
            if (1 === preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
                $fence = null === $fence ? $m[1][0] : ($m[1][0] === $fence ? null : $fence);
                continue;
            }
            if (null === $fence && 1 === preg_match('/^#{1,6} (.+)$/', $line, $m)) {
                $slug = self::slug($m[1]);
                $unique = $slug;
                for ($n = 1; \in_array($unique, $anchors, true); ++$n) {
                    $unique = $slug . '-' . $n;
                }
                $anchors[] = $unique;
            }
        }

        return $anchors;
    }

    /**
     * Le texte rendu d'un titre (sans la syntaxe Markdown en ligne), puis le slugify de VitePress.
     */
    private static function slug(string $heading): string
    {
        $text = (string) preg_replace(['/\[([^\]]*)\]\([^)]*\)/', '/[`*]/'], ['$1', ''], $heading);
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KD);
        $text = (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', \is_string($normalized) ? $normalized : $text);
        $text = (string) preg_replace('/[\x00-\x1f]/', '', $text);
        $text = (string) preg_replace('/[\s~`!@#$%^&*()\-_+=\[\]{}|\\\\;:"\'“”‘’<>,.?\/]+/u', '-', $text);
        $text = trim((string) preg_replace('/-{2,}/', '-', $text), '-');

        return strtolower((string) preg_replace('/^(\d)/', '_$1', $text));
    }
}
