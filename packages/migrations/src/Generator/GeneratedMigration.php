<?php

declare(strict_types=1);

namespace Prism\Migrations\Generator;

use Prism\Migrations\Operation\Operation;

/**
 * Le contenu d'une migration générée par db:migrations:diff.
 *
 * @internal
 */
final class GeneratedMigration
{
    /**
     * @param list<Operation> $up
     * @param list<Operation> $down         l'inverse, dans l'ordre d'exécution ; vide si irréversible
     * @param list<string>    $irreversible ce que up() détruit sans retour possible (RG-06.7)
     * @param list<string>    $warnings     renommages possibles laissés en drop + add (RG-06.3)
     */
    public function __construct(
        public readonly array $up,
        public readonly array $down,
        public readonly array $irreversible,
        public readonly array $warnings,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->up;
    }

    public function isReversible(): bool
    {
        return [] === $this->irreversible;
    }
}
