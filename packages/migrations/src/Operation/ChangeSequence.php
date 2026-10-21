<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;

/**
 * Remplace la définition d'une séquence existante (début, pas).
 *
 * @internal créée par MigrationBuilder
 */
final class ChangeSequence implements SchemaOperation
{
    public function __construct(public readonly Sequence $sequence)
    {
    }

    public function describe(): array
    {
        return ['operation' => 'changeSequence', 'sequence' => Describe::sequence($this->sequence)];
    }

    public function apply(Schema $schema): Schema
    {
        foreach ($schema->getSequences() as $sequence) {
            if (0 === strcasecmp($sequence->name, $this->sequence->name)) {
                return $schema->withSequence($this->sequence);
            }
        }

        throw InvalidSchemaException::invalidKey('sequence', $this->sequence->name, 'no such sequence.');
    }
}
