<?php

declare(strict_types=1);

namespace Prism\Dbal\Type;

use Prism\Dbal\Exception\ConversionException;
use Prism\Dbal\ParameterType;
use Prism\Dbal\Platform\AbstractPlatform;

/**
 * Conversion d'une valeur entre PHP et la base. Point d'extension pour les types personnalisés :
 * une sous-classe est sans état et s'enregistre par TypeRegistry::register().
 *
 * null est toujours converti en null, dans les deux sens.
 *
 * @api
 */
abstract class Type
{
    /** Nom unique dans le registre, utilisé par le mapping (#[Column(type: …)]). */
    abstract public function getName(): string;

    /**
     * @return scalar|null valeur prête à être liée en paramètre
     *
     * @throws ConversionException
     */
    abstract public function toDatabase(mixed $value, ConversionContext $context): mixed;

    /**
     * @throws ConversionException
     */
    abstract public function toPhp(mixed $value, ConversionContext $context): mixed;

    public function getBindingType(): ParameterType
    {
        return ParameterType::String;
    }

    /**
     * Le SQL d'un paramètre de ce type : le marqueur seul par défaut. Un type que la base ne
     * sait pas lire depuis une valeur liée l'enveloppe (VEC_FromText(:p) sous MariaDB).
     */
    public function getParameterSql(string $placeholder, AbstractPlatform $platform): string
    {
        return $placeholder;
    }
}
