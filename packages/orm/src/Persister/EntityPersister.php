<?php

declare(strict_types=1);

namespace Prism\Orm\Persister;

use Prism\Dbal\LockMode;
use Prism\Dbal\Query\Order;
use Prism\Dbal\Schema\VectorDistance;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Filter\FilterOperation;

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
    public function loadById(mixed $id, FilterOperation $operation = FilterOperation::Read): ?array;

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
    public function loadBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0, FilterOperation $operation = FilterOperation::Read): array;

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
     * Une colonne de plusieurs lignes, par identifiant (valeurs de la base) : le chargement par
     * lot d'un champ paresseux (RG-09.6). Une ligne absente n'est pas rendue.
     *
     * @param list<mixed> $ids
     *
     * @return list<array{mixed, mixed}> [identifiant, valeur]
     */
    public function loadColumn(string $column, array $ids): array;

    /**
     * Les lignes les plus proches d'un vecteur (RG-09.4), filtrées et triées par la base, chacune
     * avec sa distance sous la colonne prism_distance ; les vecteurs paresseux y sont lus. Une
     * recherche que l'index a laissée incomplète est relancée sans lui (MariaDB, ADR 0017).
     *
     * @param list<float>          $vector
     * @param array<string, mixed> $criteria    champ → valeur PHP
     * @param string|null          $staleColumn colonne _stale d'un #[EmbeddedVector] : seules les
     *                                          lignes à jour (RG-09.8)
     *
     * @return list<array<string, mixed>>
     *
     * @throws MappingException champ inconnu
     */
    public function loadNearest(string $column, array $vector, VectorDistance $distance, int $limit, array $criteria = [], ?string $staleColumn = null): array;

    /**
     * Les identifiants les plus proches d'un vecteur, du plus proche au plus lointain, filtrés
     * comme loadNearest() : les candidats vectoriels de hybridSearch() (RG-09.5).
     *
     * @param list<float>          $vector
     * @param array<string, mixed> $criteria
     *
     * @return list<mixed> identifiants, valeurs de la base
     *
     * @throws MappingException champ inconnu
     */
    public function loadNearestIds(string $column, array $vector, VectorDistance $distance, int $limit, array $criteria = [], ?string $staleColumn = null): array;

    /**
     * Les identifiants des lignes qui contiennent au moins un mot cherché, du plus pertinent au
     * moins pertinent, filtrés : les candidats plein texte de hybridSearch() (RG-09.5).
     *
     * @param list<string>         $columns  colonnes de l'index plein texte
     * @param array<string, mixed> $criteria
     *
     * @return list<mixed> identifiants, valeurs de la base
     *
     * @throws MappingException champ inconnu
     */
    public function loadFullTextIds(array $columns, ?string $language, string $query, int $limit, array $criteria = []): array;

    /**
     * Des colonnes de plusieurs lignes, par identifiant, filtrées (valeurs de la base), avec
     * l'identifiant ; $lock : verrou d'écriture jusqu'à la fin de la transaction (ADR 0018).
     *
     * @param list<string> $columns
     * @param list<mixed>  $ids
     *
     * @return list<array<string, mixed>>
     */
    public function loadColumns(array $columns, array $ids, bool $lock = false): array;

    /**
     * Pagination sur clé des identifiants (valeurs de la base), filtrés ; avec $flag, seulement
     * les lignes où cette colonne booléenne est vraie.
     *
     * @return list<mixed>
     */
    public function loadIdsAfter(?string $flag, mixed $afterId, int $limit): array;

    /**
     * UPDATE de toutes les lignes que les filtres laissent écrire.
     *
     * @param array<string, mixed> $changes colonne → valeur de la base
     *
     * @return int lignes touchées
     */
    public function updateAll(array $changes): int;

    /**
     * Nombre de lignes par valeurs des colonnes, filtrées, sous la colonne prism_count.
     *
     * @param non-empty-list<string> $columns
     *
     * @return list<array<string, mixed>>
     */
    public function countGroupedBy(array $columns): array;

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
