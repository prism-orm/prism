<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Orm\Exception\MappingException;

/**
 * Mappe un champ scalaire sur une colonne.
 *
 * Tout paramètre null est déduit : type et nullable depuis le type PHP (RG-02.1, RG-02.3),
 * enumType depuis un enum backed, name par la NamingStrategy.
 *
 * - precision/scale : obligatoires pour decimal (vérifié par le MetadataValidator) ;
 * - dimensions : obligatoire pour vector ;
 * - sensitive : valeur masquée dans l'audit ;
 * - lazy : champ exclu de l'hydratation par défaut (vector, gros text) ;
 * - updatable: false : colonne générée ou calculée en base.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column implements MappingAttribute
{
    public readonly ?string $name;

    public readonly ?string $type;

    public readonly ?int $length;

    public readonly ?int $precision;

    public readonly ?int $scale;

    public readonly ?int $dimensions;

    /** @var class-string<\BackedEnum>|null */
    public readonly ?string $enumType;

    /**
     * @param class-string<\BackedEnum>|null $enumType
     */
    public function __construct(
        ?string $name = null,
        ?string $type = null,
        ?int $length = null,
        public readonly ?bool $nullable = null,
        public readonly bool $unique = false,
        ?int $precision = null,
        ?int $scale = null,
        ?int $dimensions = null,
        ?string $enumType = null,
        public readonly mixed $default = null,
        public readonly bool $sensitive = false,
        public readonly bool $lazy = false,
        public readonly bool $insertable = true,
        public readonly bool $updatable = true,
    ) {
        $this->name = AttributeGuard::name('Column', 'name', $name);
        $this->type = AttributeGuard::name('Column', 'type', $type);
        $this->length = AttributeGuard::positiveInt('Column', 'length', $length);
        $this->precision = AttributeGuard::positiveInt('Column', 'precision', $precision, 1, 65);
        $this->scale = AttributeGuard::positiveInt('Column', 'scale', $scale, 0, 30);
        $this->dimensions = AttributeGuard::positiveInt('Column', 'dimensions', $dimensions, 1, 16000);

        if (null !== $this->scale && null !== $this->precision && $this->scale > $this->precision) {
            throw MappingException::invalidAttributeArgument('Column', 'scale', \sprintf('scale (%d) cannot exceed precision (%d).', $this->scale, $this->precision));
        }

        $this->enumType = AttributeGuard::backedEnum('Column', 'enumType', $enumType);

        if (!$insertable && !$updatable && null !== $default) {
            throw MappingException::invalidAttributeArgument('Column', 'default', 'a read-only column (insertable: false, updatable: false) cannot declare a default value.');
        }
    }
}
