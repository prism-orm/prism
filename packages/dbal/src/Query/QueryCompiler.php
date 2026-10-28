<?php

declare(strict_types=1);

namespace Prism\Dbal\Query;

use Prism\Dbal\ParameterType;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Type\TypeRegistry;

/**
 * État d'une génération de SQL : nomme les valeurs liées :prism_1, :prism_2… dans l'ordre
 * d'apparition, pour que deux requêtes identiques produisent le même SQL. Une valeur typée par
 * son nom reçoit le SQL de paramètre de son type (Type::getParameterSql()).
 *
 * @internal
 */
final class QueryCompiler
{
    /** @var array<string, mixed> */
    public private(set) array $parameters = [];

    /** @var array<string, ParameterType|string> */
    public private(set) array $types = [];

    private int $counter = 0;

    public function __construct(
        public readonly AbstractPlatform $platform,
        private readonly TypeRegistry $typeRegistry,
    ) {
    }

    public function table(string $table, ?string $alias): string
    {
        $sql = $this->platform->quoteIdentifier($table);

        return null === $alias ? $sql : $sql . ' ' . $this->platform->quoteIdentifier($alias);
    }

    public function expression(Expression $expression): string
    {
        $sql = '';
        foreach ($expression->getParts() as $part) {
            $sql .= \is_string($part) ? $part : $this->bind($part);
        }

        return $sql;
    }

    public function value(BoundValue|string $value): string
    {
        return \is_string($value) ? $value : $this->bind($value);
    }

    public function named(string $name, BoundValue $value): void
    {
        $this->add($name, $value);
    }

    private function bind(BoundValue $value): string
    {
        $name = 'prism_' . ++$this->counter;
        $this->add($name, $value);

        return \is_string($value->type) ? $this->typeRegistry->get($value->type)->getParameterSql(':' . $name, $this->platform) : ':' . $name;
    }

    private function add(string $name, BoundValue $value): void
    {
        $this->parameters[$name] = $value->value;
        if (null !== $value->type) {
            $this->types[$name] = $value->type;
        }
    }
}
