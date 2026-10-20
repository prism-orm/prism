<?php

declare(strict_types=1);

namespace Prism\Orm\Query;

use Prism\Dbal\Query\Order;

/**
 * Critère de tri d'une requête typée : $o->createdAt->desc(), $o->total->sum()->desc().
 *
 * @api
 */
final class Ordering
{
    public function __construct(
        public readonly Operand $operand,
        public readonly Order $order,
    ) {
    }
}
