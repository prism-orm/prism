<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Orm\Exception\QueryException;
use Prism\Orm\Query\JsonPath;

/**
 * Champ de type json : se compare par ses chemins.
 *
 *     $t->payload->path('customer', 'city')->asString()->eq('Lyon')
 *     $t->payload->path('lines', 0, 'qty')->asInt()->gt(2)
 *
 * @api
 */
final class JsonField extends Field
{
    /**
     * Chemin dans le document : clés de lettres, chiffres et _, index de tableau entiers.
     *
     * @throws QueryException chemin vide ou clé invalide
     */
    public function path(string|int ...$keys): JsonPath
    {
        return new JsonPath($this, array_values($keys));
    }
}
