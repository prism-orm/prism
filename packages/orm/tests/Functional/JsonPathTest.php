<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Query\Condition;
use Prism\Orm\Tests\Unit\Query\Fixtures\CustomerTotal;
use Prism\Orm\Tests\Unit\Query\Fixtures\Tally;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\AllTypes_;
use Prism\Orm\Tests\Unit\Query\Metamodel\Fixtures\Priority;

/**
 * Chemins JSON sur les 4 bases (ADR 0010) : clés imbriquées et index, null JSON et chemin
 * absent à NULL, conversions en entier et en décimal, groupement par un chemin.
 *
 * Différences documentées, hors de ces tests : un booléen JSON se lit « 1 » sous SQLite et
 * « true » ailleurs ; le texte d'un nombre ou d'un objet dépend de la base.
 */
final class JsonPathTest extends MappingSchemaTestCase
{
    private EntityManager $em;

    protected static function entities(): array
    {
        return [AllTypes::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = new EntityManager($this->connection);

        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            foreach ([
                1 => ['customer' => ['city' => 'Lyon'], 'lines' => [['qty' => 3], ['qty' => 1]], 'amount' => 10.5, 'note' => null],
                2 => ['customer' => ['city' => 'Paris'], 'lines' => [['qty' => 1]], 'amount' => 99.99],
                3 => ['customer' => ['city' => 'Lyon'], 'lines' => [['qty' => 12]], 'amount' => 100],
                4 => ['customer' => [], 'lines' => []],
            ] as $id => $json) {
                $row = new AllTypes();
                $row->id = $id;
                $row->string = 's' . $id;
                $row->text = 't' . $id;
                $row->uuid = \sprintf('0192f0a0-0000-7000-8000-00000000000%d', $id);
                $row->decimal = '1.00';
                $row->datetime = new \DateTimeImmutable('2026-10-20 08:00:00');
                $row->json = $json;
                $row->binary = str_repeat(\chr($id), 16);
                $row->priority = Priority::Low;
                $em->persist($row);
            }
        });
        $this->em->clear();
    }

    /**
     * @return list<int>
     */
    private function ids(AllTypes_ $t, Condition $condition): array
    {
        return array_map(static fn (AllTypes $r): int => $r->id, $this->em->query($t)->where($condition)->orderBy($t->id->asc())->getResult());
    }

    public function testNestedKeysAndIndexes(): void
    {
        $t = AllTypes_::as('t');

        self::assertSame([1, 3], $this->ids($t, $t->json->path('customer', 'city')->asString()->eq('Lyon')));
        self::assertSame([2], $this->ids($t, $t->json->path('customer', 'city')->asString()->in(['Paris', 'Nice'])));
        self::assertSame([1, 3], $this->ids($t, $t->json->path('lines', 0, 'qty')->asInt()->gt(2)), 'compared as integers: 12 > 2');
        self::assertSame([1], $this->ids($t, $t->json->path('lines', 1, 'qty')->asInt()->eq(1)));
    }

    public function testDecimalIsComparedAsANumber(): void
    {
        $t = AllTypes_::as('t');

        self::assertSame([2, 3], $this->ids($t, $t->json->path('amount')->asDecimal()->gte('99.99')));
        self::assertSame([1], $this->ids($t, $t->json->path('amount')->asDecimal()->between('10.00', '10.50')));
    }

    public function testMissingPathAndJsonNullAreNull(): void
    {
        $t = AllTypes_::as('t');

        self::assertSame([1, 2, 3, 4], $this->ids($t, $t->json->path('note')->asString()->isNull()), 'JSON null (1) and missing key (2, 3, 4)');
        self::assertSame([4], $this->ids($t, $t->json->path('customer', 'city')->asString()->isNull()));
        self::assertSame([1, 2, 3], $this->ids($t, $t->json->path('amount')->asDecimal()->isNotNull()));
    }

    public function testOrderByAPath(): void
    {
        $t = AllTypes_::as('t');

        $rows = $this->em->query($t)->where($t->json->path('lines', 0, 'qty')->asInt()->isNotNull())->orderBy($t->json->path('lines', 0, 'qty')->asInt()->desc())->getResult();

        self::assertSame([3, 1, 2], array_map(static fn (AllTypes $r): int => $r->id, $rows), '12, 3, 1: numeric order');
    }

    public function testGroupByAPath(): void
    {
        $t = AllTypes_::as('t');
        $city = $t->json->path('customer', 'city')->asString();

        $totals = $this->em->query($t)->where($city->isNotNull())->groupBy($city)->orderBy($city->asc())
            ->selectInto(CustomerTotal::class, customer: $city, orders: $t->id->count())
            ->getResult();

        self::assertEquals([new CustomerTotal('Lyon', 2), new CustomerTotal('Paris', 1)], $totals);
    }

    public function testSelectAPathValue(): void
    {
        $t = AllTypes_::as('t');

        $tally = $this->em->query($t)->where($t->id->eq(1))
            ->selectInto(Tally::class, count: $t->id, qty: $t->json->path('lines', 0, 'qty')->asInt(), city: $t->json->path('customer', 'city')->asString(), amount: $t->json->path('amount')->asDecimal())
            ->getOneOrNull();

        self::assertNotNull($tally);
        self::assertSame(1, $tally->count);
        self::assertSame(3, $tally->extra['qty']);
        self::assertSame('Lyon', $tally->extra['city']);
        self::assertIsString($tally->extra['amount']);
        self::assertEqualsWithDelta(10.5, (float) $tally->extra['amount'], 0.0001, 'its scale depends on the platform');
    }
}
