<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\CyclicDependencyException;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\UnitOfWork\CommitOrderCalculator;
use Prism\Orm\UnitOfWork\Dependency;

/**
 * Scénarios de référence du CommitOrderCalculator, écrits avant son implémentation.
 *
 * Une dépendance « from → to » signifie que from porte une FK vers to : to est inséré d'abord.
 *
 * Règles fixées ici, en complément de RG-04.3 et RG-04.4 :
 * - tri stable : entre entités indépendantes, l'ordre d'ajout (celui de persist()) est gardé ;
 * - un cycle est rompu sur une FK nullable, et sur le moins de FK possible : les dépendances
 *   sont gardées dans leur ordre de déclaration, la première qui fermerait un cycle est différée ;
 * - seules les dépendances d'un cycle sont différées, jamais celles qui y mènent ;
 * - un cycle sans FK nullable lève CyclicDependencyException, qui le décrit.
 */
final class CommitOrderCalculatorTest extends TestCase
{
    /**
     * @param list<string>                              $nodes        dans l'ordre d'ajout
     * @param list<array{string, string, string, bool}> $dependencies from, to, champ, nullable
     *
     * @return array{list<string>, list<string>} ordre, puis dépendances différées « from.champ→to »
     */
    private static function sort(array $nodes, array $dependencies = []): array
    {
        $calculator = new CommitOrderCalculator();
        $ids = array_flip($nodes);
        foreach ($nodes as $id => $label) {
            $calculator->addNode($id, $label);
        }
        foreach ($dependencies as [$from, $to, $field, $nullable]) {
            $calculator->addDependency($ids[$from], $ids[$to], $field, $nullable);
        }
        $order = $calculator->sort();

        return [
            array_map(static fn (int $id): string => $nodes[$id], $order->nodes),
            array_map(static fn (Dependency $d): string => \sprintf('%s.%s→%s', $nodes[$d->from], $d->field, $nodes[$d->to]), $order->deferred),
        ];
    }

    // ---------------------------------------------------------------- sans cycle

    public function testNothingToOrder(): void
    {
        self::assertSame([[], []], self::sort([]));
    }

    public function testIndependentEntitiesKeepTheirOrder(): void
    {
        self::assertSame([['A', 'B', 'C'], []], self::sort(['A', 'B', 'C']));
    }

    // RG-04.3
    public function testTargetIsInsertedBeforeTheEntityThatReferencesIt(): void
    {
        self::assertSame(
            [['Client', 'Invoice', 'Line'], []],
            self::sort(['Line', 'Invoice', 'Client'], [['Line', 'Invoice', 'invoice', false], ['Invoice', 'Client', 'client', false]]),
        );
    }

    public function testOrderIsStableBetweenIndependentChains(): void
    {
        self::assertSame(
            [['A1', 'B1', 'A2', 'B2'], []],
            self::sort(['A1', 'B1', 'A2', 'B2'], [['A2', 'A1', 'parent', false], ['B2', 'B1', 'parent', false]]),
        );
    }

    public function testOnlyWhatMustMoveMoves(): void
    {
        // C ne dépend de rien : il reste devant ; A attend B
        self::assertSame(
            [['C', 'B', 'A', 'D'], []],
            self::sort(['C', 'A', 'B', 'D'], [['A', 'B', 'b', false]]),
        );
    }

    public function testDiamond(): void
    {
        // C, persisté avant B, passe devant lui une fois A inséré
        self::assertSame(
            [['A', 'C', 'B', 'D'], []],
            self::sort(['D', 'C', 'B', 'A'], [['D', 'B', 'b', false], ['D', 'C', 'c', false], ['B', 'A', 'a', true], ['C', 'A', 'a', false]]),
        );
    }

    public function testSeveralForeignKeysBetweenTheSameEntities(): void
    {
        self::assertSame(
            [['Client', 'Invoice'], []],
            self::sort(['Invoice', 'Client'], [['Invoice', 'Client', 'billedTo', false], ['Invoice', 'Client', 'shippedTo', true]]),
        );
    }

    // catégorie auto-référencée : des entités de la même classe, persistées à l'envers
    public function testTreeOfTheSameClass(): void
    {
        self::assertSame(
            [['Root', 'Child', 'Grandchild'], []],
            self::sort(['Grandchild', 'Child', 'Root'], [['Grandchild', 'Child', 'parent', true], ['Child', 'Root', 'parent', true]]),
        );
    }

    // ---------------------------------------------------------------- cycles (RG-04.4)

    public function testCycleIsBrokenOnTheNullableForeignKey(): void
    {
        // Order.customer obligatoire, Customer.lastOrder nullable : Customer d'abord, à NULL
        self::assertSame(
            [['Customer', 'Order'], ['Customer.lastOrder→Order']],
            self::sort(['Order', 'Customer'], [['Order', 'Customer', 'customer', false], ['Customer', 'Order', 'lastOrder', true]]),
        );
    }

    public function testCycleOfNullableForeignKeysDefersOnlyOne(): void
    {
        // la première dépendance déclarée est gardée, la seconde ferme le cycle
        self::assertSame(
            [['B', 'A'], ['B.a→A']],
            self::sort(['A', 'B'], [['A', 'B', 'b', true], ['B', 'A', 'a', true]]),
        );
    }

    public function testNullableSelfReference(): void
    {
        self::assertSame([['Employee'], ['Employee.mentor→Employee']], self::sort(['Employee'], [['Employee', 'Employee', 'mentor', true]]));
    }

    public function testCycleOfThreeIsBrokenOnItsOnlyNullableForeignKey(): void
    {
        self::assertSame(
            [['C', 'B', 'A'], ['C.a→A']],
            self::sort(['A', 'B', 'C'], [['A', 'B', 'b', false], ['B', 'C', 'c', false], ['C', 'A', 'a', true]]),
        );
    }

    public function testDependenciesLeadingToACycleAreNeverDeferred(): void
    {
        // D → A n'est pas dans le cycle A ↔ B : seule B.a est différée
        self::assertSame(
            [['B', 'A', 'D'], ['B.a→A']],
            self::sort(['D', 'A', 'B'], [['D', 'A', 'a', true], ['A', 'B', 'b', false], ['B', 'A', 'a', true]]),
        );
    }

    public function testTwoSeparateCycles(): void
    {
        self::assertSame(
            [['B', 'A', 'D', 'C'], ['B.a→A', 'D.c→C']],
            self::sort(['A', 'B', 'C', 'D'], [['A', 'B', 'b', false], ['B', 'A', 'a', true], ['C', 'D', 'd', false], ['D', 'C', 'c', true]]),
        );
    }

    // la FK qui ferme le cycle n'est pas la première de son entité
    public function testCycleThroughTheSecondForeignKeyOfAnEntity(): void
    {
        self::assertSame(
            [['B', 'C', 'A'], ['B.a→A']],
            self::sort(['A', 'B', 'C'], [['A', 'C', 'c', false], ['A', 'B', 'b', false], ['B', 'A', 'a', true]]),
        );
    }

    // dans le cycle, B est atteint deux fois (S → B, S → A → B) avant T : la recherche continue
    public function testCycleFoundBehindAnEntityReachedTwice(): void
    {
        self::assertSame(
            [['B', 'T', 'A', 'S'], ['B.s→S', 'T.s→S']],
            self::sort(['S', 'A', 'B', 'T'], [['S', 'A', 'a', false], ['S', 'B', 'b', false], ['A', 'B', 'b', false], ['A', 'T', 't', false], ['B', 'S', 's', true], ['T', 'S', 's', true]]),
        );
    }

    public function testCycleWithoutNullableForeignKeyIsRefused(): void
    {
        $this->expectException(CyclicDependencyException::class);
        $this->expectExceptionMessage('Cannot order the INSERTs of this flush: Order -[customer]-> Customer -[firstOrder]-> Order is a cycle of non-nullable foreign keys. Make one of these relations nullable.');

        self::sort(['Order', 'Customer'], [['Order', 'Customer', 'customer', false], ['Customer', 'Order', 'firstOrder', false]]);
    }

    public function testNonNullableSelfReferenceIsRefused(): void
    {
        $this->expectException(CyclicDependencyException::class);
        $this->expectExceptionMessage('Cannot order the INSERTs of this flush: Employee -[manager]-> Employee is a cycle of non-nullable foreign keys.');

        self::sort(['Employee'], [['Employee', 'Employee', 'manager', false]]);
    }

    public function testNullableForeignKeyOutsideTheRigidCycleDoesNotSaveIt(): void
    {
        // A ↔ B sans FK nullable ; B → C nullable n'y change rien
        $this->expectException(CyclicDependencyException::class);
        $this->expectExceptionMessage('A -[b]-> B -[a]-> A');

        self::sort(['A', 'B', 'C'], [['A', 'B', 'b', false], ['B', 'A', 'a', false], ['B', 'C', 'c', true], ['C', 'B', 'b', true]]);
    }

    // ---------------------------------------------------------------- usage

    public function testDependencyOnAnUnknownEntityIsRefused(): void
    {
        $calculator = new CommitOrderCalculator();
        $calculator->addNode(1, 'Line');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown entity 2 in the commit order: add it with addNode() first.');

        $calculator->addDependency(1, 2, 'invoice', false);
    }

    public function testDependencyFromAnUnknownEntityIsRefused(): void
    {
        $calculator = new CommitOrderCalculator();
        $calculator->addNode(1, 'Invoice');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown entity 3 in the commit order');

        $calculator->addDependency(3, 1, 'invoice', false);
    }

    public function testDependenciesAreReported(): void
    {
        $calculator = new CommitOrderCalculator();
        $calculator->addNode(10, 'Customer');
        $calculator->addNode(20, 'Order');
        $calculator->addDependency(10, 20, 'lastOrder', true);
        $calculator->addDependency(20, 10, 'customer', false);

        $order = $calculator->sort();

        self::assertSame([10, 20], $order->nodes);
        self::assertCount(1, $order->deferred);
        self::assertSame([10, 20, 'lastOrder', true], [$order->deferred[0]->from, $order->deferred[0]->to, $order->deferred[0]->field, $order->deferred[0]->nullable]);
    }

    // un flush de 10 000 INSERT chaînés : pas de récursion profonde, temps linéaire
    public function testLongChain(): void
    {
        $calculator = new CommitOrderCalculator();
        for ($i = 0; $i < 10_000; ++$i) {
            $calculator->addNode($i, 'N' . $i);
            if ($i > 0) {
                $calculator->addDependency($i - 1, $i, 'next', false);
            }
        }

        $start = hrtime(true);
        $order = $calculator->sort();

        self::assertSame(range(9_999, 0, -1), $order->nodes);
        self::assertLessThan(500, (hrtime(true) - $start) / 1e6, 'milliseconds');
    }
}
