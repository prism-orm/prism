<?php

declare(strict_types=1);

namespace Prism\Migrations\Tests\Fixtures\Data;

use Prism\Migrations\AbstractMigration;
use Prism\Migrations\MigrationBuilder;

// la migration de schéma de la version : un index, avant les données de la même version
final class Version20261021110000 extends AbstractMigration
{
    public function up(MigrationBuilder $m): void
    {
        $m->createIndex('dt_customer', ['full_name']);
    }

    public function down(MigrationBuilder $m): void
    {
        $m->dropIndex('dt_customer', ['full_name']);
    }
}
