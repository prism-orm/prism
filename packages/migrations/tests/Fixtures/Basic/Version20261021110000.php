<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Basic;

use Prism\Dbal\Schema\Column;
use Prism\Migrations\AbstractMigration;
use Prism\Migrations\MigrationBuilder;

final class Version20261021110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment date, client email';
    }

    public function up(MigrationBuilder $m): void
    {
        $m->addColumn('mg_invoice', new Column('paid_at', 'datetime_immutable', nullable: true));
        $m->renameColumn('mg_client', 'mail', 'email');
        $m->sql('UPDATE mg_invoice SET ref = UPPER(ref) WHERE ref LIKE :prefix', ['prefix' => 'f-%']);
    }

    public function down(MigrationBuilder $m): void
    {
        $m->renameColumn('mg_client', 'email', 'mail');
        $m->dropColumn('mg_invoice', 'paid_at');
    }
}
