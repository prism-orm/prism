<?php

declare(strict_types=1);

namespace Prism\Website\Tests\Support;

/**
 * Un bloc de code clôturé d'une page de la documentation : « ```php no-run [Symfony] ».
 *
 * @internal
 */
final readonly class CodeBlock
{
    public const string NO_RUN = 'no-run';

    /** Les marqueurs reconnus après le langage ; un autre (faute de frappe) est refusé. */
    public const array FLAGS = [self::NO_RUN];

    /**
     * @param string       $file  chemin depuis la racine du monorepo
     * @param int          $line  ligne de la clôture ouvrante
     * @param list<string> $flags marqueurs après le langage, sans les titres d'onglet [..] de VitePress
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $language,
        public array $flags,
        public string $code,
    ) {
    }

    public function runs(): bool
    {
        return !\in_array(self::NO_RUN, $this->flags, true);
    }

    public function name(): string
    {
        return $this->file . ':' . $this->line;
    }
}
