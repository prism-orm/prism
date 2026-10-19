<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Connection;
use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Comparator;
use Prism\Dbal\Schema\Schema;
use Prism\Dbal\Schema\Sequence;
use Prism\Dbal\Schema\Table;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\Id\IdentityGenerator;
use Prism\Orm\Id\SequenceGenerator;

// RG-02.4 : identifiants générés par la base, sur les plateformes qui les supportent
final class IdGenerationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (null === TestDatabase::url()) {
            self::markTestSkipped('DATABASE_URL is not set.');
        }
        $this->connection = TestDatabase::connection();
        $this->migrate(new Schema());
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->migrate(new Schema());
            $this->connection->close();
        }
    }

    public function testIdentityValueIsReadAfterInsert(): void
    {
        $this->migrate(new Schema([new Table('id_identity', [new Column('id', 'bigint', autoincrement: true), new Column('label', 'string', length: 10)], ['id'])]));
        $generator = new IdentityGenerator();

        $ids = [];
        foreach (['a', 'b'] as $label) {
            $this->connection->createQueryBuilder()->insert('id_identity')->set('label', $label)->executeStatement();
            $ids[] = (int) $generator->generate($this->connection);
        }

        self::assertSame([1, 2], $ids);
        self::assertTrue($generator->isPostInsert());
    }

    public function testSequenceValueIsKnownBeforeInsert(): void
    {
        if (!$this->connection->getPlatform()->supportsSequences()) {
            self::markTestSkipped(\sprintf('%s has no sequences.', $this->connection->getPlatform()->getName()));
        }
        $this->migrate(new Schema([], [new Sequence('id_test_seq', 1000, 10)]));
        $generator = new SequenceGenerator('id_test_seq');

        self::assertSame([1000, 1010], [(int) $generator->generate($this->connection), (int) $generator->generate($this->connection)]);
    }

    private function migrate(Schema $target): void
    {
        $actual = $this->connection->createSchemaManager()->introspect();
        $mine = new Schema(
            array_values(array_filter($actual->getTables(), static fn (Table $t): bool => str_starts_with($t->name, 'id_'))),
            array_values(array_filter($actual->getSequences(), static fn (Sequence $s): bool => str_starts_with($s->name, 'id_'))),
        );
        $this->connection->createSchemaManager()->migrate(new Comparator($this->connection->getPlatform())->compare($mine, $target));
    }
}
