<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Concurrent;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\MigrationBuilder;

// RG-06.5 : CREATE INDEX CONCURRENTLY sous PostgreSQL, hors transaction ; sans effet ailleurs
final class Version20261021100000 extends AbstractMigration
{
    public function up(MigrationBuilder $m): void
    {
        $m->createTable(new Table('lt_concurrent', [new Column('id', 'integer'), new Column('ref', 'string', length: 20)], ['id']));
        $m->createIndex('lt_concurrent', ['ref'], unique: true, concurrently: true);
    }

    public function down(MigrationBuilder $m): void
    {
        $m->dropTable('lt_concurrent');
    }
}
