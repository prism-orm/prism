<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

/**
 * Une erreur de mapping trouvée par le MetadataValidator.
 *
 * @api
 */
final class MappingError implements \Stringable
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public readonly string $class,
        public readonly ?string $field,
        public readonly string $message,
    ) {
    }

    public function __toString(): string
    {
        return null === $this->field ? \sprintf('%s: %s', $this->class, $this->message) : \sprintf('%s::$%s: %s', $this->class, $this->field, $this->message);
    }
}
