<?php

declare(strict_types=1);

namespace Prism\Dbal\Schema;

/**
 * Noms d'index et de clés déterministes (RG-01.7) : « {préfixe}_{table}_{hash8} », en minuscules,
 * identiques sur toutes les plateformes pour que le diff de schéma soit stable.
 *
 * @internal
 */
final class NameGenerator
{
    // limite la plus courte des plateformes supportées (PostgreSQL : 63 octets)
    public const int MAX_LENGTH = 63;

    /**
     * @param list<string> $columns
     */
    public static function generate(string $prefix, string $table, array $columns): string
    {
        $hash = substr(hash('sha256', strtolower($table) . '(' . strtolower(implode(',', $columns)) . ')'), 0, 8);
        $readable = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $table));
        $room = self::MAX_LENGTH - \strlen($prefix) - \strlen($hash) - 2;

        return $prefix . '_' . substr($readable, 0, $room) . '_' . $hash;
    }
}
