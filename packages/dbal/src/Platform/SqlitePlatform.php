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

    // SQLite n'a pas de type décimal : NUMERIC passe par un flottant (ou un entier) et perd
    // silencieusement les chiffres au-delà de 15 ; DecimalType refuse ces valeurs (RG-01.5)
    public function getDecimalMaxSignificantDigits(): int
    {
        return 15;
    }

    // SQLite : LIMIT -1 signifie « sans limite »
    protected function getOffsetWithoutLimitSql(int $offset): string
    {
        return ' LIMIT -1 OFFSET ' . $offset;
    }

    // SQLite ignore les clés étrangères tant qu'on ne les active pas, connexion par connexion
    public function getSessionInitSql(): array
    {
        return ['PRAGMA foreign_keys = ON'];
    }
}
