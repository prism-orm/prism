<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Query\Order;
use Prism\Orm\Query\Metamodel\Field;

/**
 * Critère de tri d'une requête typée : $o->createdAt->desc().
 *
 * @api
 */
final class Ordering
{
    public function __construct(
        public readonly Field $field,
        public readonly Order $order,
    ) {
    }
}
