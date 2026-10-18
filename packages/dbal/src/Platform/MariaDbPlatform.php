<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

/**
 * @api
 */
final class MariaDbPlatform extends AbstractMySqlPlatform
{
    public function getName(): string
    {
        return 'MariaDB';
    }

    public function supportsSequences(): bool
    {
        return true;
    }

    // type natif depuis MariaDB 10.7 : 16 octets, rendu sous forme canonique
    protected function getUuidDeclarationSql(): string
    {
        return 'UUID';
    }

    // ADR 0003 : première LTS avec le type VECTOR
    public function getMinimumVersion(): string
    {
        return '11.8.0';
    }
}
