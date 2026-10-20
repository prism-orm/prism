<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\ParameterType;
use Prism\Orm\Exception\NonUniqueResultException;
use Prism\Orm\Exception\QueryException;

/**
 * Requête typée projetée dans un DTO (RG-05.6, RG-05.7, ADR 0010) : chaque ligne construit un
 * DTO par arguments nommés, ses valeurs converties par le Type de leur champ ou de leur
 * expression. Rien ne passe par l'UoW.
 *
 * Le constructeur du DTO est vérifié dès selectInto() ; une valeur d'un autre type que son
 * paramètre lève une QueryException à la construction.
 *
 * @template D of object
 *
 * @api
 */
final class Projection
{
    /** @var array<string, Operand> */
    private readonly array $selections;

    /**
     * @param EntityQueryBuilder<object> $query
     * @param class-string<D>            $dto
     * @param array<int|string, Operand> $selections
     *
     * @throws QueryException sélection sans nom, nom ou paramètre sans correspondance
     *
     * @internal créée par EntityQueryBuilder::selectInto()
     */
    public function __construct(
        private readonly EntityQueryBuilder $query,
        private readonly string $dto,
        array $selections,
    ) {
        $named = [];
        foreach ($selections as $name => $operand) {
            if (!\is_string($name)) {
                throw QueryException::unnamedSelection($dto);
            }
            $named[$name] = $operand;
        }
        $this->selections = $named;

        $parameters = new \ReflectionClass($dto)->getConstructor()?->getParameters() ?? [];
        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters);
        $variadic = [] !== array_filter($parameters, static fn (\ReflectionParameter $p): bool => $p->isVariadic());
        foreach (array_keys($named) as $name) {
            if (!$variadic && !\in_array($name, $names, true)) {
                throw QueryException::unknownDtoParameter($dto, $name, $names);
            }
        }
        foreach ($parameters as $parameter) {
            if (!$parameter->isOptional() && !\array_key_exists($parameter->getName(), $named)) {
                throw QueryException::missingDtoParameter($dto, $parameter->getName());
            }
        }
    }

    /**
     * @return list<D>
     *
     * @throws QueryException
     */
    public function getResult(): array
    {
        return $this->fetch(one: false);
    }

    /**
     * @return D|null
     *
     * @throws NonUniqueResultException plusieurs lignes
     * @throws QueryException
     */
    public function getOneOrNull(): ?object
    {
        $result = $this->fetch(one: true);
        if (\count($result) > 1) {
            throw NonUniqueResultException::forQuery($this->dto, $this->toSql());
        }

        return $result[0] ?? null;
    }

    /**
     * @throws QueryException
     */
    public function toSql(): string
    {
        return $this->query->compileProjection($this->selections, one: false)[0]->toSql();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws QueryException
     */
    public function getParameters(): array
    {
        return $this->query->compileProjection($this->selections, one: false)[0]->getParameters();
    }

    /**
     * @return array<string, ParameterType|string>
     *
     * @throws QueryException
     */
    public function getParameterTypes(): array
    {
        return $this->query->compileProjection($this->selections, one: false)[0]->getParameterTypes();
    }

    /**
     * @return list<D>
     *
     * @throws QueryException
     */
    private function fetch(bool $one): array
    {
        [$qb, $context, $aliases] = $this->query->compileProjection($this->selections, $one);
        $dtos = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $arguments = [];
            foreach ($this->selections as $name => $operand) {
                $arguments[$name] = $operand->toPhp($context, $row[$aliases[$name]] ?? null);
            }
            try {
                $dtos[] = new ($this->dto)(...$arguments);
            } catch (\TypeError $e) {
                throw QueryException::dtoTypeMismatch($this->dto, $e);
            }
        }

        return $dtos;
    }
}
