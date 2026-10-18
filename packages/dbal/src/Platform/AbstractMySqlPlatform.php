<?php

declare(strict_types=1);

namespace Prism\Dbal\Platform;

use Prism\Dbal\Exception\InvalidIdentifierException;
use Prism\Dbal\Exception\InvalidParameterException;

/**
 * Socle commun MySQL / MariaDB ; les divergences (VECTOR, JSON, index) vivent dans les sous-classes.
 *
 * @api
 */
abstract class AbstractMySqlPlatform extends AbstractPlatform
{
    /**
     * Le backslash est un caractère d'échappement avec le sql_mode par défaut : il est doublé.
     * Un serveur en NO_BACKSLASH_ESCAPES n'est pas encore géré (voir docs/BACKLOG.md).
     */
    public function quoteStringLiteral(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw InvalidParameterException::nulByteInLiteral();
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    public function getMaxIdentifierLength(): int
    {
        return 64;
    }

    // MySQL n'a pas de « LIMIT ALL » : la documentation recommande le plus grand entier non signé
    protected function getOffsetWithoutLimitSql(int $offset): string
    {
        return ' LIMIT 18446744073709551615 OFFSET ' . $offset;
    }

    protected function getIdentifierQuoteCharacter(): string
    {
        return '`';
    }

    // MySQL compte la limite en caractères, pas en octets
    protected function assertIdentifierLength(string $name): void
    {
        $length = preg_match_all('/./su', $name);
        if ($length > $this->getMaxIdentifierLength()) {
            throw InvalidIdentifierException::tooLong($this->getName(), $name, (int) $length, $this->getMaxIdentifierLength(), 'characters');
        }
    }
}
