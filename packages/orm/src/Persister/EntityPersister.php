<?php

declare(strict_types=1);

namespace Prism\Orm\Persister;

use Prism\Dbal\LockMode;
use Prism\Dbal\Query\Order;
use Prism\Orm\Exception\MappingException;

/**
 * Écrit et lit les lignes d'une entité. Les valeurs reçues et rendues sont celles de la base
 * (déjà converties par les Type du DBAL) : l'UnitOfWork compare et convertit, le persister écrit.
 * Seuls les critères de recherche sont des valeurs PHP, convertis par le persister.
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

    /**
     * Lignes qui satisfont les critères (champ → valeur PHP ; null : IS NULL ; liste : IN).
     *
     * @param array<string, mixed> $criteria
     * @param array<string, Order> $orderBy  champ → sens
     *
     * @return list<array<string, mixed>>
     *
     * @throws MappingException champ inconnu
     */
    public function loadBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array;

    /**
     * Pagination sur clé : au plus $limit lignes d'identifiant strictement supérieur à $afterId
     * (valeur de la base ; null : depuis le début), triées par identifiant.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<array<string, mixed>>
     *
     * @throws MappingException champ inconnu
     */
    public function loadAfter(array $criteria, mixed $afterId, int $limit): array;

    /**
     * @param array<string, mixed> $criteria
     *
     * @throws MappingException champ inconnu
     */
    public function count(array $criteria = []): int;

    /**
     * Verrou de ligne pessimiste, tenu jusqu'à la fin de la transaction en cours.
     *
     * @return bool faux si la ligne n'existe pas
     */
    public function lock(mixed $id, LockMode $mode): bool;

    /**
     * Lignes de cette entité liées aux propriétaires $ownerIds par la table pivot $table, en une
     * jointure : chaque ligne avec l'identifiant de son propriétaire (valeur de la base).
     *
     * @param non-empty-list<mixed> $ownerIds
     * @param array<string, Order>  $orderBy  champ de cette entité → sens
     *
     * @return list<array{mixed, array<string, mixed>}>
     */
    public function loadByJoinTable(string $table, string $ownerColumn, string $targetColumn, array $ownerIds, string $ownerType, array $orderBy = []): array;

    /**
     * Nombre de lignes de cette entité liées au propriétaire $ownerId par la table pivot $table ;
     * appelée sur le persister de la cible, pour que ses filtres s'appliquent.
     */
    public function countJoinTable(string $table, string $ownerColumn, string $targetColumn, mixed $ownerId, string $ownerType): int;

    /**
     * @param array<string, mixed>  $row   colonne → valeur de la base
     * @param array<string, string> $types colonne → type DBAL
     */
    public function insertJoinRow(string $table, array $row, array $types): void;

    /**
     * Supprime les lignes pivot qui satisfont tous les critères.
     *
     * @param array<string, mixed>  $criteria colonne → valeur de la base
     * @param array<string, string> $types    colonne → type DBAL
     */
    public function deleteJoinRows(string $table, array $criteria, array $types): int;
}
