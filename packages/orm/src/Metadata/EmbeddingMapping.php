<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

/**
 * Un champ vecteur #[EmbeddedVector] (RG-09.7, ADR 0018) : ses champs source, le nom de son
 * fournisseur, et les deux colonnes que l'ORM gère sans propriété : <colonne>_stale (vrai tant
 * que le vecteur ne correspond pas aux sources) et <colonne>_model (le modèle qui l'a produit).
 *
 * @api
 */
final readonly class EmbeddingMapping
{
    /** longueur de la colonne du modèle */
    public const int MODEL_LENGTH = 255;

    /**
     * @param list<string> $source champs texte concaténés, dans l'ordre
     *
     * @internal construite par Prism (AttributeDriver, MetadataValidator) ; lisible, pas à construire (ADR 0020)
     */
    public function __construct(
        public string $field,
        public array $source,
        public string $provider,
        public string $staleColumn,
        public string $modelColumn,
    ) {
    }
}
