<?php

declare(strict_types=1);

namespace Prism\Dbal;

/**
 * Résultat d'une requête de lecture. Les valeurs sont celles du driver ; la conversion
 * en types PHP (decimal, dates…) relève de TypeRegistry.
 *
 * @api
 */
final class Result
{
    /**
     * @internal créé par Connection::executeQuery()
     */
    public function __construct(private readonly \PDOStatement $statement)
    {
    }

    /**
     * @return array<string, mixed>|false false quand il n'y a plus de ligne
     */
    public function fetchAssociative(): array|false
    {
        $row = $this->statement->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return false;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * @return list<mixed>|false false quand il n'y a plus de ligne
     */
    public function fetchNumeric(): array|false
    {
        $row = $this->statement->fetch(\PDO::FETCH_NUM);

        return \is_array($row) ? $row : false;
    }

    /**
     * Première colonne de la ligne suivante ; false quand il n'y a plus de ligne
     * (une colonne NULL renvoie null).
     */
    public function fetchOne(): mixed
    {
        $row = $this->fetchNumeric();

        return false === $row ? false : $row[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllAssociative(): array
    {
        $rows = [];
        while (false !== $row = $this->fetchAssociative()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<mixed>
     */
    public function fetchFirstColumn(): array
    {
        $values = [];
        while (false !== $row = $this->fetchNumeric()) {
            $values[] = $row[0];
        }

        return $values;
    }

    /**
     * Parcourt les lignes sans les charger toutes en mémoire.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateAssociative(): \Generator
    {
        while (false !== $row = $this->fetchAssociative()) {
            yield $row;
        }
    }

    /**
     * Lignes concernées par la dernière requête d'écriture ; sous MySQL et MariaDB, les lignes
     * trouvées et non seulement modifiées, comme sur les autres plateformes.
     */
    public function rowCount(): int
    {
        return $this->statement->rowCount();
    }

    public function columnCount(): int
    {
        return $this->statement->columnCount();
    }

    public function free(): void
    {
        $this->statement->closeCursor();
    }
}
