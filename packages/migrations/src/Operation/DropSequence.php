<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Schema;

/**
 * Supprime une séquence.
 *
 * @internal créée par MigrationBuilder
 */
final class DropSequence implements SchemaOperation
{
    public function __construct(
        public readonly string $sequence,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'dropSequence', 'sequence' => $this->sequence];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withoutSequence($this->sequence);
    }
}
