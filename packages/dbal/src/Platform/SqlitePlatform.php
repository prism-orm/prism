<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

/**
 * Tests et dev uniquement (périmètre 1.0).
 *
 * @api
 */
final class SqlitePlatform extends AbstractPlatform
{
    public function getName(): string
    {
        return 'SQLite';
    }

    public function getMinimumVersion(): string
    {
        return '3.40.0';
    }

    // SQLite ignore les clés étrangères tant qu'on ne les active pas, connexion par connexion
    public function getSessionInitSql(): array
    {
        return ['PRAGMA foreign_keys = ON'];
    }
}
