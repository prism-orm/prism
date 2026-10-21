<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\DataFailing;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\MigrationBuilder;

final class Version20261021100000 extends AbstractMigration
{
    public function up(MigrationBuilder $m): void
    {
        $m->createTable(new Table('dt_customer', [new Column('id', 'integer'), new Column('full_name', 'string', length: 80), new Column('first_name', 'string', nullable: true, length: 40)], ['id']));
    }

    public function down(MigrationBuilder $m): void
    {
        $m->dropTable('dt_customer');
    }
}
