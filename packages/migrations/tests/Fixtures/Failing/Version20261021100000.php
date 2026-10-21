<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Failing;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\MigrationBuilder;

// le DDL passe, puis l'instruction suivante échoue (RG-03.6 pour PostgreSQL, RG-06.6 ailleurs)
final class Version20261021100000 extends AbstractMigration
{
    public function up(MigrationBuilder $m): void
    {
        $m->createTable(new Table('mg_partial', [new Column('id', 'integer')], ['id']));
        $m->sql('INSERT INTO mg_missing_table (id) VALUES (1)');
    }

    public function down(MigrationBuilder $m): void
    {
        $m->dropTable('mg_partial');
    }
}
