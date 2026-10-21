<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Schema\Schema;

/**
 * Une opération de schéma : appliquée au schéma introspecté, la Platform en écrit le SQL.
 *
 * @internal
 */
interface SchemaOperation extends Operation
{
    /**
     * @throws InvalidSchemaException table, colonne ou index inconnu, nom déjà pris
     */
    public function apply(Schema $schema): Schema;
}
