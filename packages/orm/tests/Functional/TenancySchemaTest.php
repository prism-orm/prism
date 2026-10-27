<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Schema\Comparator;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tests\Unit\Tools\Fixtures\Ledger;
use Prism\Orm\Tests\Unit\Tools\Fixtures\Note;
use Prism\Orm\Tests\Unit\Tools\Fixtures\PlainNote;
use Prism\Orm\Tests\Unit\Tools\Fixtures\SharedRate;
use Prism\Orm\Tools\SchemaTool;

/**
 * RG-08.1 sur les 4 bases : colonne du tenant, son index et les index qu'elle préfixe
 * s'appliquent sans laisser de diff (RG-01.8) ; une entité qui devient tenantée migre.
 */
final class TenancySchemaTest extends MappingSchemaTestCase
{
    protected static function entities(): array
    {
        return [Note::class, Ledger::class, SharedRate::class];
    }

    public function testAppliedTenancySchemaLeavesNoDiff(): void
    {
        $diff = new Comparator($this->connection->getPlatform())->compare($this->actual(), $this->target());

        self::assertTrue($diff->isEmpty(), 'Remaining diff: ' . implode(' | ', $this->connection->getPlatform()->getMigrationSql($diff)));

        $note = $this->actual()->getTable('note');
        self::assertFalse($note->getColumn('tenant_id')->nullable);
        self::assertTrue($this->actual()->getTable('shared_rate')->getColumn('tenant_id')->nullable, 'global: true');
    }

    public function testEntityThatBecomesTenantedMigrates(): void
    {
        $platform = $this->connection->getPlatform();
        $plain = new SchemaTool($platform)->fromMetadata([new AttributeDriver()->loadMetadata(PlainNote::class)]);
        $this->migrateTo($plain);

        $sql = $platform->getMigrationSql(new Comparator($platform)->compare($this->actual(), $this->target()));
        self::assertNotSame([], $sql);
        self::assertStringContainsString($platform->quoteIdentifier('tenant_id'), implode("\n", $sql));

        $this->migrateTo($this->target());
        self::assertTrue(new Comparator($platform)->compare($this->actual(), $this->target())->isEmpty());
    }
}
