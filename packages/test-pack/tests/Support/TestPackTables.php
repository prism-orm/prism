<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Support;

use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\TestPack\Database;

/**
 * Fin d'une classe de test du test-pack sur base réelle (RG-00.1) : ses tables et séquences
 * supprimées, et Database oublie qu'elles étaient prêtes.
 */
final class TestPackTables
{
    public static function drop(): void
    {
        Database::forget();
        if (null === TestDatabase::url()) {
            return;
        }
        $connection = TestDatabase::connection();
        $platform = $connection->getPlatform();
        foreach (['tp_book', 'tp_author', 'tp_invoice'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table));
        }
        if ('PostgreSQL' === $platform->getName()) {
            foreach (['tp_book_id_seq', 'tp_author_id_seq', 'tp_invoice_id_seq'] as $sequence) {
                $connection->executeStatement('DROP SEQUENCE IF EXISTS ' . $platform->quoteIdentifier($sequence));
            }
        }
        $connection->close();
    }
}
