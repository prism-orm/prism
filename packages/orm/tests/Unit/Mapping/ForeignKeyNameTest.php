<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Schema\ForeignKeyAction;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping as Prism;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tools\SchemaTool;

/**
 * Le nom de la clé étrangère et l'action NO ACTION dans le mapping (db:import, RG-06.10) : une base
 * existante est reprise telle quelle, sans migration.
 */
final class ForeignKeyNameTest extends TestCase
{
    public function testNamedForeignKeyAndNoAction(): void
    {
        $driver = new AttributeDriver();
        $metadata = [$driver->loadMetadata(FkParent::class), $driver->loadMetadata(FkChild::class)];
        self::assertSame('FK_LEGACY_PARENT', $metadata[1]->getAssociation('parent')->foreignKeyName);

        $table = new SchemaTool(new PostgreSqlPlatform('16'), TypeRegistry::withDefaults())->fromMetadata($metadata)->getTable('fk_child');
        $fks = $table->getForeignKeys();

        self::assertSame(['FK_LEGACY_PARENT', ForeignKeyAction::NoAction], [$fks[0]->name, $fks[0]->onDelete]);
        self::assertCount(1, array_filter($table->getIndexes(), static fn ($i): bool => $i->unique), 'the declared unique constraint is the only one: no second unique index');
    }

    public function testInverseSideCannotNameTheForeignKey(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the inverse side cannot declare joinColumn, onDelete or foreignKey');

        new Prism\OneToOne(mappedBy: 'parent', foreignKey: 'FK_X');
    }
}

#[Prism\Entity]
#[Prism\Table('fk_parent')]
final class FkParent
{
    #[Prism\Id]
    public int $id = 0;
}

#[Prism\Entity]
#[Prism\Table('fk_child')]
#[Prism\UniqueConstraint(['parent'], name: 'UNIQ_LEGACY_PARENT')]
final class FkChild
{
    #[Prism\Id]
    public int $id = 0;

    public function __construct(
        #[Prism\OneToOne(onDelete: Prism\OnDelete::NoAction, foreignKey: 'FK_LEGACY_PARENT')]
        public FkParent $parent,
    ) {
    }
}
