<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Schema\Comparator;
use Prism\Orm\Tests\Functional\Fixtures\Author;
use Prism\Orm\Tests\Functional\Fixtures\Book;
use Prism\Orm\Tests\Functional\Fixtures\Label;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Inferred;
use Prism\Orm\Tests\Unit\Metadata\Fixtures\Person;

/**
 * RG-01.8 appliqué au mapping : le schéma déduit des entités, une fois appliqué et relu,
 * ne laisse aucun diff (base du critère « second --dump-sql vide » de l'étape 6).
 */
final class MappingSchemaRoundTripTest extends MappingSchemaTestCase
{
    protected static function entities(): array
    {
        return [Author::class, Book::class, Label::class, Person::class, Inferred::class];
    }

    public function testAppliedMappingSchemaLeavesNoDiff(): void
    {
        $diff = new Comparator($this->connection->getPlatform())->compare($this->actual(), $this->target());

        self::assertTrue($diff->isEmpty(), 'Remaining diff: ' . implode(' | ', $this->connection->getPlatform()->getMigrationSql($diff)));
        self::assertCount(6, $this->actual()->getTables(), 'author, book, label, book_label, people, inferred');
    }

    public function testForeignKeysFromTheMappingAreEnforced(): void
    {
        $q = $this->connection->getPlatform()->quoteIdentifier(...);
        $this->connection->executeStatement(\sprintf('INSERT INTO %s (%s, %s) VALUES (1, ?)', $q('author'), $q('id'), $q('name')), ['Hugo']);
        $this->connection->executeStatement(\sprintf('INSERT INTO %s (%s, %s, %s, %s) VALUES (10, ?, ?, 1)', $q('book'), $q('id'), $q('title'), $q('price'), $q('author_id')), ['Les Misérables', '12.50']);

        // onDelete: SetNull de Book::$author
        $this->connection->executeStatement(\sprintf('DELETE FROM %s', $q('author')));

        self::assertNull($this->connection->executeQuery(\sprintf('SELECT %s FROM %s', $q('author_id'), $q('book')))->fetchOne());
    }
}
