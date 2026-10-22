<?php

declare(strict_types=1);

namespace Prism\Bundle\Maker;

/**
 * La commande make:prism:migration (ADR 0013) : make:migration sans DoctrineBundle, make:prism:migration à côté de lui.
 *
 * @internal
 */
final class MakePrismMigration extends AbstractMakeMigration
{
    public static function getCommandName(): string
    {
        return 'make:prism:migration';
    }
}
