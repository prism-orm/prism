<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Schema\Schema;

/**
 * Renomme une séquence en gardant son compteur : avec le renommage de sa table sous
 * PostgreSQL ({table}_id_seq). Écrite par le SQL de renommage de la Platform (ADR 0012).
 *
 * @internal créée par MigrationBuilder
 */
final class RenameSequence implements SchemaOperation
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
    }

    public function describe(): array
    {
        return ['operation' => 'renameSequence', 'from' => $this->from, 'to' => $this->to];
    }

    public function apply(Schema $schema): Schema
    {
        return $schema->withRenamedSequence($this->from, $this->to);
    }
}
