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

    // ADR 0003 : première LTS avec le type VECTOR
    public function getMinimumVersion(): string
    {
        return '11.8.0';
    }
}
