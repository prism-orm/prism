<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema\Introspection;

use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;

/**
 * @internal
 */
interface Introspector
{
    /**
     * @return list<string>
     */
    public function listTableNames(): array;

    public function introspectTable(string $name): Table;

    /**
     * @return list<Sequence>
     */
    public function listSequences(): array;

    /**
     * Les colonnes lues avec une perte, par « table.colonne » : ce que Prism ne représente pas.
     *
     * @return array<string, string>
     */
    public function notes(): array;
}
