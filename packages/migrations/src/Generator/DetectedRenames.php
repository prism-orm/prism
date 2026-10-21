<?php

declare(strict_types=1);

namespace Prism\Migrations\Generator;

use Prism\Dbal\Schema\Schema;
use Prism\Migrations\Operation\Operation;

/**
 * @internal
 */
final class DetectedRenames
{
    /**
     * @param Schema          $from     le schéma de départ, renommages appliqués
     * @param list<Operation> $renames  dans l'ordre où les appliquer
     * @param list<string>    $warnings candidats laissés en drop + add (mode non interactif)
     */
    public function __construct(
        public readonly Schema $from,
        public readonly array $renames,
        public readonly array $warnings,
    ) {
    }
}
