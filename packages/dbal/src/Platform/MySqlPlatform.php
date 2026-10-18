<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

/**
 * @api
 */
final class MySqlPlatform extends AbstractMySqlPlatform
{
    public function getName(): string
    {
        return 'MySQL';
    }

    public function getMinimumVersion(): string
    {
        return '8.0.0';
    }
}
