<?php

declare(strict_types=1);

namespace Prism\Orm\Exception;

/**
 * Erreur de mapping détectée à la lecture d'un attribut ou à la validation de la metadata.
 *
 * @api
 */
final class MappingException extends \LogicException implements OrmException
{
    public static function invalidAttributeArgument(string $attribute, string $argument, string $reason): self
    {
        return new self(\sprintf('Invalid argument "%s" for #[%s]: %s', $argument, $attribute, $reason));
    }
}
