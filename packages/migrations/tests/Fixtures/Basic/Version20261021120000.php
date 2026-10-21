<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Basic;

use Prism\Migrations\AbstractMigration;
use Prism\Migrations\MigrationBuilder;

// sans down() : irréversible (RG-06.7)
final class Version20261021120000 extends AbstractMigration
{
    public function up(MigrationBuilder $m): void
    {
        $m->renameTable('mg_invoice', 'mg_bill');
    }
}
