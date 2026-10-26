<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

/**
 * Colonne du tenant d'une entité #[Tenanted] (RG-08.1, ADR 0014). Son type est celui de
 * l'EntityManager, commun à toutes ses entités. Sans $field, la colonne n'est portée par aucune
 * propriété : l'ORM l'écrit et la filtre seul ; avec $field, la propriété la rend lisible.
 * global : colonne nullable, une ligne à NULL est partagée par tous les tenants.
 *
 * @api
 */
final class TenancyMapping
{
    /** types acceptés pour l'identifiant du tenant */
    public const array TYPES = ['integer', 'bigint', 'uuid'];

    public function __construct(
        public readonly string $column,
        public readonly string $type = 'integer',
        public readonly bool $global = false,
        public readonly ?string $field = null,
    ) {
    }
}
