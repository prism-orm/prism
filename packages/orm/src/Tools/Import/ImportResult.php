<?php

declare(strict_types=1);

namespace Prism\Orm\Tools\Import;

/**
 * Ce que db:import tire d'une base (RG-06.10).
 *
 * @internal
 */
final class ImportResult
{
    /**
     * @param array<string, string> $entities       chemin relatif du fichier → code de l'entité
     * @param array<string, string> $skipped        table non importée → raison
     * @param array<string, string> $foreignKeys    clé étrangère gardée en colonne (« table.contrainte ») → raison
     * @param array<string, string> $approximations ce que le mapping ne reproduit pas (« table.colonne ») :
     *                                              le prochain diff le verra
     */
    public function __construct(
        public readonly array $entities,
        public readonly array $skipped,
        public readonly array $foreignKeys,
        public readonly array $approximations = [],
    ) {
    }
}
