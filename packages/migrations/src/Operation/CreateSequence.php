<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;

/**
 * Crée une séquence (PostgreSQL, MariaDB).
 *
 * @internal créée par MigrationBuilder
 */
final class CreateSequence implements SchemaOperation
{
    public function __construct(
        public readonly Sequence $sequence,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'createSequence', 'sequence' => Describe::sequence($this->sequence)];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withSequence($this->sequence);
    }
}
