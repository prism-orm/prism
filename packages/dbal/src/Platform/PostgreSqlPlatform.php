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

    // au-delà, PostgreSQL tronque l'identifiant sans erreur : Prism refuse avant
    public function getMaxIdentifierLength(): int
    {
        return 63;
    }

    // valeur par défaut depuis 9.1, mais modifiable par l'administrateur ; quoteStringLiteral() en dépend
    public function getSessionInitSql(): array
    {
        return ['SET standard_conforming_strings = on'];
    }

    public function failedStatementAbortsTransaction(): bool
    {
        return true;
    }
}
