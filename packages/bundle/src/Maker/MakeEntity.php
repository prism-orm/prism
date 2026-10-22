<?php

declare(strict_types=1);

namespace Prism\Bundle\Maker;

/**
 * La commande make:entity (ADR 0013) : make:entity sans DoctrineBundle, make:prism:entity à côté de lui.
 *
 * @internal
 */
final class MakeEntity extends AbstractMakeEntity
{
    public static function getCommandName(): string
    {
        return 'make:entity';
    }
}
