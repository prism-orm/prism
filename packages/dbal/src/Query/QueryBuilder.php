<?php

declare(strict_types=1);

namespace Prism\Dbal\Query;

use Prism\Dbal\Connection;
use Prism\Dbal\Exception\InvalidQueryException;
use Prism\Dbal\LockMode;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Result;

/**
 * Construction de requêtes SQL, immuable : chaque méthode retourne une copie, une requête de
 * base peut donc être déclinée (comptage, page) sans effet de bord.
 *
 * - Les noms de table, d'alias et de colonne passés à from(), join(), insert(), update(),
 *   delete() et set() sont cités (RG-01.3).
 * - Les expressions (select(), where(), conditions de jointure) sont du SQL brut ; column()
 *   cite un nom réservé à l'intérieur d'une expression.
 * - Les valeurs n'entrent que par des paramètres liés (RG-01.4).
 *
 * @api
 */
final class QueryBuilder
{
    private const string NAME_PATTERN = '/^(?!prism_)[A-Za-z_][A-Za-z0-9_]*$/';

    private QueryType $type = QueryType::Select;

    private bool $distinct = false;

    /** @var list<string> */
    private array $select = [];

    /** @var array{string, ?string}|null table, alias */
    private ?array $from = null;

    /** @var list<array{string, string, string, Expression}> type, table, alias, condition */
    private array $joins = [];

    private ?string $table = null;

    /** @var list<array{string, BoundValue|string}> colonne, valeur liée ou SQL brut */
    private array $values = [];

    private ?Expression $where = null;

    /** @var list<string> */
    private array $groupBy = [];

    private ?Expression $having = null;

    /** @var list<array{string, Order}> */
    private array $orderBy = [];

    private ?int $limit = null;

    private int $offset = 0;

    private ?LockMode $lock = null;

    /** @var array<string, BoundValue> */
    private array $parameters = [];

    /**
     * @internal utiliser Connection::createQueryBuilder()
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    public function expr(): ExpressionBuilder
    {
        return new ExpressionBuilder();
    }

    /**
     * Nom de colonne cité, éventuellement préfixé par un alias : column('order', 'i') → "i"."order".
     */
    public function column(string $name, ?string $alias = null): string
    {
        $platform = $this->connection->getPlatform();

        return (null === $alias ? '' : $platform->quoteIdentifier($alias) . '.') . $platform->quoteIdentifier($name);
    }

    public function select(string ...$expressions): self
    {
        return $this->with(static function (self $qb) use ($expressions): void {
            $qb->type = QueryType::Select;
            $qb->select = array_values($expressions);
        });
    }

    public function addSelect(string ...$expressions): self
    {
        return $this->with(static function (self $qb) use ($expressions): void {
            $qb->type = QueryType::Select;
            array_push($qb->select, ...array_values($expressions));
        });
    }

    public function distinct(bool $distinct = true): self
    {
        return $this->with(static function (self $qb) use ($distinct): void {
            $qb->distinct = $distinct;
        });
    }

    public function from(string $table, ?string $alias = null): self
    {
        return $this->with(static function (self $qb) use ($table, $alias): void {
            $qb->from = [$table, $alias];
        });
    }

    /**
     * @param array<string, mixed>                $params paramètres nommés utilisés dans $on
     * @param array<string, ParameterType|string> $types
     */
    public function join(string $table, string $alias, Expression|string $on, array $params = [], array $types = []): self
    {
        return $this->addJoin('INNER JOIN', $table, $alias, $on, $params, $types);
    }

    /**
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    public function leftJoin(string $table, string $alias, Expression|string $on, array $params = [], array $types = []): self
    {
        return $this->addJoin('LEFT JOIN', $table, $alias, $on, $params, $types);
    }

    public function insert(string $table): self
    {
        return $this->writeQuery(QueryType::Insert, $table);
    }

    public function update(string $table): self
    {
        return $this->writeQuery(QueryType::Update, $table);
    }

    public function delete(string $table): self
    {
        return $this->writeQuery(QueryType::Delete, $table);
    }

    /**
     * Valeur d'une colonne pour INSERT ou UPDATE, toujours liée en paramètre.
     *
     * @param ParameterType|string|null $type ParameterType ou nom d'un Type (valeur convertie)
     */
    public function set(string $column, mixed $value, ParameterType|string|null $type = null): self
    {
        return $this->addValue($column, new BoundValue($value, $type));
    }

    /**
     * Expression SQL brute pour une colonne d'UPDATE (« qty + 1 »). Jamais de valeur utilisateur ici.
     */
    public function setExpression(string $column, string $sql): self
    {
        return $this->addValue($column, $sql);
    }

    /**
     * Remplace la condition WHERE.
     *
     * @param array<string, mixed>                $params paramètres nommés utilisés dans la condition
     * @param array<string, ParameterType|string> $types
     */
    public function where(Expression|string $condition, array $params = [], array $types = []): self
    {
        return $this->withCondition('where', null, $condition, $params, $types);
    }

    /**
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    public function andWhere(Expression|string $condition, array $params = [], array $types = []): self
    {
        return $this->withCondition('where', 'AND', $condition, $params, $types);
    }

    /**
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    public function orWhere(Expression|string $condition, array $params = [], array $types = []): self
    {
        return $this->withCondition('where', 'OR', $condition, $params, $types);
    }

    public function groupBy(string ...$expressions): self
    {
        return $this->with(static function (self $qb) use ($expressions): void {
            $qb->groupBy = array_values($expressions);
        });
    }

    /**
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    public function having(Expression|string $condition, array $params = [], array $types = []): self
    {
        return $this->withCondition('having', null, $condition, $params, $types);
    }

    /**
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    public function andHaving(Expression|string $condition, array $params = [], array $types = []): self
    {
        return $this->withCondition('having', 'AND', $condition, $params, $types);
    }

    /**
     * Remplace le tri.
     */
    public function orderBy(string $expression, Order $order = Order::Asc): self
    {
        return $this->with(static function (self $qb) use ($expression, $order): void {
            $qb->orderBy = [[$expression, $order]];
        });
    }

    public function addOrderBy(string $expression, Order $order = Order::Asc): self
    {
        return $this->with(static function (self $qb) use ($expression, $order): void {
            $qb->orderBy[] = [$expression, $order];
        });
    }

    /**
     * @throws InvalidQueryException limite négative
     */
    public function limit(?int $limit): self
    {
        if (null !== $limit && $limit < 0) {
            throw InvalidQueryException::negative('LIMIT', $limit);
        }

        return $this->with(static function (self $qb) use ($limit): void {
            $qb->limit = $limit;
        });
    }

    /**
     * @throws InvalidQueryException offset négatif
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw InvalidQueryException::negative('OFFSET', $offset);
        }

        return $this->with(static function (self $qb) use ($offset): void {
            $qb->offset = $offset;
        });
    }

    /**
     * Verrou de ligne du SELECT (FOR UPDATE, FOR SHARE), tenu jusqu'à la fin de la transaction ;
     * null le retire.
     */
    public function lock(?LockMode $mode): self
    {
        return $this->with(static function (self $qb) use ($mode): void {
            $qb->lock = $mode;
        });
    }

    /**
     * Paramètre nommé utilisé dans une expression brute (« i.total > :min »).
     *
     * @throws InvalidQueryException nom invalide ou réservé (prism_*)
     */
    public function setParameter(string $name, mixed $value, ParameterType|string|null $type = null): self
    {
        return $this->withParameters([$name => $value], null === $type ? [] : [$name => $type]);
    }

    /**
     * @throws InvalidQueryException requête incohérente
     */
    public function toSql(): string
    {
        return $this->compile()[0];
    }

    /**
     * Valeurs liées, par nom de paramètre (sans « : »), dans l'ordre d'apparition.
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->compile()[1];
    }

    /**
     * @return array<string, ParameterType|string>
     */
    public function getParameterTypes(): array
    {
        return $this->compile()[2];
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @throws InvalidQueryException
     */
    public function executeQuery(): Result
    {
        [$sql, $params, $types] = $this->compile();

        return $this->connection->executeQuery($sql, $params, $types);
    }

    /**
     * @return int nombre de lignes concernées
     *
     * @throws InvalidQueryException
     */
    public function executeStatement(): int
    {
        [$sql, $params, $types] = $this->compile();

        return $this->connection->executeStatement($sql, $params, $types);
    }

    /**
     * @param \Closure(self): void $change
     */
    private function with(\Closure $change): self
    {
        $copy = clone $this;
        $change($copy);

        return $copy;
    }

    private function writeQuery(QueryType $type, string $table): self
    {
        return $this->with(static function (self $qb) use ($type, $table): void {
            $qb->type = $type;
            $qb->table = $table;
        });
    }

    private function addValue(string $column, BoundValue|string $value): self
    {
        if (QueryType::Insert !== $this->type && QueryType::Update !== $this->type) {
            throw InvalidQueryException::clauseNotAllowed('set()', $this->type->value);
        }

        return $this->with(static function (self $qb) use ($column, $value): void {
            $qb->values[] = [$column, $value];
        });
    }

    /**
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    private function addJoin(string $type, string $table, string $alias, Expression|string $on, array $params, array $types): self
    {
        $condition = ExpressionBuilder::expression($on);

        return $this->withParameters($params, $types)->with(static function (self $qb) use ($type, $table, $alias, $condition): void {
            $qb->joins[] = [$type, $table, $alias, $condition];
        });
    }

    /**
     * @param 'where'|'having'                    $clause
     * @param 'AND'|'OR'|null                     $operator null : remplace la condition
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    private function withCondition(string $clause, ?string $operator, Expression|string $condition, array $params, array $types): self
    {
        $condition = ExpressionBuilder::expression($condition);

        return $this->withParameters($params, $types)->with(static function (self $qb) use ($clause, $operator, $condition): void {
            $current = $qb->{$clause};
            $qb->{$clause} = null === $operator || null === $current
                ? $condition
                : Expression::join(' ' . $operator . ' ', $current->wrapped(), $condition->wrapped());
        });
    }

    /**
     * @param array<string, mixed>                $params
     * @param array<string, ParameterType|string> $types
     */
    private function withParameters(array $params, array $types): self
    {
        if ([] === $params) {
            return $this;
        }

        foreach (array_keys($params) as $name) {
            if (1 !== preg_match(self::NAME_PATTERN, $name)) {
                throw InvalidQueryException::invalidParameterName($name);
            }
        }

        return $this->with(static function (self $qb) use ($params, $types): void {
            foreach ($params as $name => $value) {
                $qb->parameters[$name] = new BoundValue($value, $types[$name] ?? null);
            }
        });
    }

    /**
     * @return array{string, array<string, mixed>, array<string, ParameterType|string>}
     */
    private function compile(): array
    {
        $compiler = new QueryCompiler($this->connection->getPlatform());

        $sql = match ($this->type) {
            QueryType::Select => $this->compileSelect($compiler),
            QueryType::Insert => $this->compileInsert($compiler),
            QueryType::Update => $this->compileUpdate($compiler),
            QueryType::Delete => $this->compileDelete($compiler),
        };

        foreach ($this->parameters as $name => $value) {
            $compiler->named($name, $value);
        }

        return [$sql, $compiler->parameters, $compiler->types];
    }

    private function compileSelect(QueryCompiler $c): string
    {
        if ([] === $this->select && null === $this->from) {
            throw InvalidQueryException::emptySelect();
        }

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . ([] === $this->select ? '*' : implode(', ', $this->select));

        if (null !== $this->from) {
            $sql .= ' FROM ' . $c->table(...$this->from);
        }
        foreach ($this->joins as [$type, $table, $alias, $on]) {
            $sql .= ' ' . $type . ' ' . $c->table($table, $alias) . ' ON ' . $c->expression($on);
        }
        if (null !== $this->where) {
            $sql .= ' WHERE ' . $c->expression($this->where);
        }
        if ([] !== $this->groupBy) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if (null !== $this->having) {
            $sql .= ' HAVING ' . $c->expression($this->having);
        }
        if ([] !== $this->orderBy) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(static fn (array $o): string => $o[0] . ' ' . $o[1]->value, $this->orderBy));
        }

        $sql = $c->platform->applyLimit($sql, $this->limit, $this->offset);

        return null === $this->lock ? $sql : $sql . $c->platform->getLockSql($this->lock);
    }

    private function compileInsert(QueryCompiler $c): string
    {
        $table = $this->writeTable();
        $this->assertOnlyWhere(false);
        if ([] === $this->values) {
            throw InvalidQueryException::noValues('INSERT');
        }

        $columns = [];
        $values = [];
        foreach ($this->values as [$column, $value]) {
            $columns[] = $c->platform->quoteIdentifier($column);
            $values[] = $c->value($value);
        }

        return \sprintf('INSERT INTO %s (%s) VALUES (%s)', $c->platform->quoteIdentifier($table), implode(', ', $columns), implode(', ', $values));
    }

    private function compileUpdate(QueryCompiler $c): string
    {
        $table = $this->writeTable();
        $this->assertOnlyWhere(true);
        if ([] === $this->values) {
            throw InvalidQueryException::noValues('UPDATE');
        }

        $sets = [];
        foreach ($this->values as [$column, $value]) {
            $sets[] = $c->platform->quoteIdentifier($column) . ' = ' . $c->value($value);
        }

        $sql = 'UPDATE ' . $c->platform->quoteIdentifier($table) . ' SET ' . implode(', ', $sets);

        return null === $this->where ? $sql : $sql . ' WHERE ' . $c->expression($this->where);
    }

    private function compileDelete(QueryCompiler $c): string
    {
        $table = $this->writeTable();
        $this->assertOnlyWhere(true);

        $sql = 'DELETE FROM ' . $c->platform->quoteIdentifier($table);

        return null === $this->where ? $sql : $sql . ' WHERE ' . $c->expression($this->where);
    }

    private function writeTable(): string
    {
        return $this->table ?? throw InvalidQueryException::missingTable($this->type->value);
    }

    // jointures, tri et limite n'ont pas de syntaxe portable en écriture
    private function assertOnlyWhere(bool $whereAllowed): void
    {
        $clauses = [
            'FROM' => null !== $this->from,
            'JOIN' => [] !== $this->joins,
            'GROUP BY' => [] !== $this->groupBy,
            'HAVING' => null !== $this->having,
            'ORDER BY' => [] !== $this->orderBy,
            'LIMIT' => null !== $this->limit,
            'OFFSET' => $this->offset > 0,
            'DISTINCT' => $this->distinct,
            'LOCK' => null !== $this->lock,
            'WHERE' => !$whereAllowed && null !== $this->where,
        ];

        foreach ($clauses as $clause => $used) {
            if ($used) {
                throw InvalidQueryException::clauseNotAllowed($clause, $this->type->value);
            }
        }
    }
}
