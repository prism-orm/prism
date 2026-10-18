<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

/**
 * @api
 */
final class PostgreSqlPlatform extends AbstractPlatform
{
    public function getName(): string
    {
        return 'PostgreSQL';
    }

    public function getMinimumVersion(): string
    {
        return '15.0.0';
    }

    public function failedStatementAbortsTransaction(): bool
    {
        return true;
    }
}
