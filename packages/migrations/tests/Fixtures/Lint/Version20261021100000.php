<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Lint;

use Prism\Dbal\Schema\Column;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\Lint\IgnoreLint;
use Prism\Migrations\MigrationBuilder;

// des opérations dangereuses sur des tables qui ont des données ; L12 assumé
#[IgnoreLint('L12', reason: 'legacy is never read')]
final class Version20261021100000 extends AbstractMigration
{
    public function up(MigrationBuilder $m): void
    {
        $m->addColumn('lt_customer', new Column('code', 'string', length: 5));
        $m->createIndex('lt_customer', ['name'], unique: true);
        $m->addForeignKey('lt_invoice', ['customer_id'], 'lt_customer', ['id']);
        $m->dropColumn('lt_customer', 'legacy');
    }

    public function down(MigrationBuilder $m): void
    {
    }
}
