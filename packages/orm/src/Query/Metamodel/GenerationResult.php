<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

/**
 * Fichiers d'une génération du metamodel, chemins relatifs au dossier de génération.
 *
 * @api
 */
final class GenerationResult
{
    /**
     * @param list<string> $written   créés ou modifiés
     * @param list<string> $unchanged identiques, non réécrits
     * @param list<string> $deleted   classes d'entités disparues
     */
    public function __construct(
        public readonly array $written,
        public readonly array $unchanged,
        public readonly array $deleted,
    ) {
    }
}
