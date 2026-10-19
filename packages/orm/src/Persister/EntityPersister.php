<?php

declare(strict_types=1);

namespace Prism\Orm\Persister;

/**
 * Écrit et lit les lignes d'une entité. Les valeurs reçues et rendues sont celles de la base
 * (déjà converties par les Type du DBAL) : l'UnitOfWork compare et convertit, le persister écrit.
 *
 * @internal
 */
interface EntityPersister
{
    /**
     * @param array<string, mixed> $row colonne → valeur
     *
     * @return int|string|null identifiant généré par la base (IDENTITY), null sinon
     */
    public function insert(array $row): int|string|null;

    /**
     * @param array<string, mixed>      $changes seulement les colonnes modifiées (RG-03.5)
     * @param array{string, mixed}|null $version colonne de version et valeur attendue (RG-03.9)
     *
     * @return int lignes touchées
     */
    public function update(mixed $id, array $changes, ?array $version = null): int;

    /**
     * @param array{string, mixed}|null $version
     *
     * @return int lignes touchées
     */
    public function delete(mixed $id, ?array $version = null): int;

    /**
     * @return array<string, mixed>|null
     */
    public function loadById(mixed $id): ?array;
}
