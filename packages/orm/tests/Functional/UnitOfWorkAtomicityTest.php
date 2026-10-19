<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Exception\QueryFailedException;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Persister\DbalPersisterProvider;
use Prism\Orm\Tests\Functional\PersisterFixtures\Label;
use Prism\Orm\UnitOfWork\EntityState;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * RG-03.6 sur base réelle : le 3e INSERT viole une contrainte d'unicité ; aucune ligne n'est
 * écrite et l'UoW revient à son état d'avant le flush, identifiants compris.
 */
final class UnitOfWorkAtomicityTest extends MappingSchemaTestCase
{
    protected static function entities(): array
    {
        return [Label::class];
    }

    private function uow(): UnitOfWork
    {
        return new UnitOfWork(new MetadataFactory(new AttributeDriver()), new DbalPersisterProvider($this->connection), new ConversionContext($this->connection->getPlatform()), TypeRegistry::withDefaults());
    }

    private function rows(): int
    {
        return new DbalPersisterProvider($this->connection)->getPersister($this->metadata()[0])->count();
    }

    // RG-03.6
    public function testFailureOnTheThirdInsertWritesNothingAndLeavesTheUnitOfWorkUnchanged(): void
    {
        $uow = $this->uow();
        $labels = [new Label('red'), new Label('green'), new Label('red')];
        foreach ($labels as $label) {
            $uow->persist($label);
        }

        try {
            $uow->commit();
            self::fail('The duplicate code must violate the unique constraint.');
        } catch (\RuntimeException $e) {
            self::assertInstanceOf(QueryFailedException::class, $e);
        }

        self::assertSame(0, $this->rows());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        foreach ($labels as $label) {
            self::assertNull($label->id, 'no identifier survives the rollback');
            self::assertSame(EntityState::Managed, $uow->getEntityState($label), 'still scheduled for insertion');
        }

        // la donnée corrigée, le même flush aboutit
        $labels[2]->code = 'blue';
        $uow->commit();

        self::assertSame(3, $this->rows());
        $ids = array_map(static fn (Label $l): ?int => $l->id, $labels);
        self::assertNotContains(null, $ids);
        self::assertCount(3, array_unique($ids));
        self::assertSame($labels[2], $uow->find(Label::class, $labels[2]->id));
    }
}
