<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

use Prism\Dbal\Exception\InvalidSchemaException;

/**
 * Ensemble des tables et séquences d'une base. Immuable.
 *
 * @api
 */
final class Schema
{
    /** @var array<string, Table> */
    private readonly array $tables;

    /** @var array<string, Sequence> */
    private readonly array $sequences;

    /**
     * @param list<Table>    $tables
     * @param list<Sequence> $sequences
     */
    public function __construct(array $tables = [], array $sequences = [])
    {
        $byName = [];
        foreach ($tables as $table) {
            if (isset($byName[strtolower($table->name)])) {
                throw InvalidSchemaException::duplicateTable($table->name);
            }
            $byName[strtolower($table->name)] = $table;
        }
        $this->tables = $byName;

        $bySequence = [];
        foreach ($sequences as $sequence) {
            $bySequence[strtolower($sequence->name)] = $sequence;
        }
        $this->sequences = $bySequence;
    }

    /**
     * @return list<Table>
     */
    public function getTables(): array
    {
        return array_values($this->tables);
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[strtolower($name)]);
    }

    public function getTable(string $name): Table
    {
        return $this->tables[strtolower($name)] ?? throw InvalidSchemaException::unknownTable($name);
    }

    /**
     * Ajoute la table, ou remplace celle de même nom.
     */
    public function withTable(Table $table): self
    {
        $tables = $this->tables;
        $tables[strtolower($table->name)] = $table;

        return new self(array_values($tables), array_values($this->sequences));
    }

    public function withoutTable(string $name): self
    {
        $tables = $this->tables;
        unset($tables[strtolower($name)]);

        return new self(array_values($tables), array_values($this->sequences));
    }

    /**
     * @return list<Sequence>
     */
    public function getSequences(): array
    {
        return array_values($this->sequences);
    }

    public function withSequence(Sequence $sequence): self
    {
        $sequences = $this->sequences;
        $sequences[strtolower($sequence->name)] = $sequence;

        return new self(array_values($this->tables), array_values($sequences));
    }
}
