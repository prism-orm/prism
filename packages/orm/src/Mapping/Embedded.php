<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Intègre un #[Embeddable] dans l'entité.
 *
 * - class : déduit du type de la propriété si null ;
 * - columnPrefix : null = « nomDuChamp_ », false = aucun préfixe, chaîne = préfixe explicite.
 *
 * Une propriété nullable (?Address) vaut null quand toutes ses colonnes sont NULL (RG-02.5).
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Embedded implements MappingAttribute
{
    /**
     * @param class-string|null $class
     */
    public function __construct(
        public readonly ?string $class = null,
        public readonly string|false|null $columnPrefix = null,
    ) {
        if (\is_string($columnPrefix)) {
            AttributeGuard::name('Embedded', 'columnPrefix', $columnPrefix);
        }
    }
}
