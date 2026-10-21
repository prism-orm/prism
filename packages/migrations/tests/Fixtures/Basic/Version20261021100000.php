<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Basic;

use Prism\Dbal\Schema\Column;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Schema\Table;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\MigrationBuilder;

final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clients and invoices';
    }

    public function up(MigrationBuilder $m): void
    {
        $m->createTable(new Table('mg_client', [new Column('id', 'integer'), new Column('mail', 'string', length: 120)], ['id']));
        $m->createTable(new Table('mg_invoice', [new Column('id', 'integer'), new Column('client_id', 'integer'), new Column('ref', 'string', length: 20)], ['id']));
        $m->addForeignKey('mg_invoice', ['client_id'], 'mg_client', ['id'], ForeignKeyAction::Cascade);
        $m->createIndex('mg_invoice', ['client_id', 'ref']);
    }

    public function down(MigrationBuilder $m): void
    {
        $m->dropTable('mg_invoice');
        $m->dropTable('mg_client');
    }
}
