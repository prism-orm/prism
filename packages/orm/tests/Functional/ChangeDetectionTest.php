<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Configuration;
use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\EntityManager;
use Prism\Orm\Tests\Functional\DetectionFixtures\Grade;
use Prism\Orm\Tests\Functional\DetectionFixtures\Specimen;

/**
 * La détection des changements sur les 4 bases, pour chaque type natif (RG-03.5, ADR 0022) :
 * une entité relue puis flushée n'écrit rien, une valeur égale en base n'est pas un changement,
 * chaque valeur modifiée est écrite une fois, puis n'est plus un changement.
 */
final class ChangeDetectionTest extends MappingSchemaTestCase
{
    private TestLogger $logger;

    private EntityManager $em;

    private int $id;

    protected static function entities(): array
    {
        return [Specimen::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
        $this->connection->close();
        $this->connection = TestDatabase::connection(new Configuration($this->logger));
        if (':memory:' === $this->connection->getDatabase()) {
            $this->migrateTo($this->target());
        }
        $this->em = new EntityManager($this->connection);
        $this->em->persist($specimen = new Specimen());
        $this->em->flush();
        $this->id = (int) $specimen->id;
        $this->em->clear();
    }

    private function load(): Specimen
    {
        $this->em->clear();
        $specimen = $this->em->find(Specimen::class, $this->id);
        self::assertNotNull($specimen);

        return $specimen;
    }

    /**
     * @return int les UPDATE du flush
     */
    private function flush(): int
    {
        $this->logger->records = [];
        $this->em->flush();

        return \count(array_filter($this->logger->sqlStatements(), static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')));
    }

    public function testAReadEntityWritesNothing(): void
    {
        $this->load();

        self::assertSame(0, $this->flush());
    }

    // les mêmes valeurs en base, écrites autrement : pas un changement
    public function testEqualValuesAreNotChanges(): void
    {
        $specimen = $this->load();
        $specimen->createdAt = new \DateTimeImmutable('2026-10-28 08:00:00');
        $specimen->day = new \DateTimeImmutable('2026-10-28 00:00:00');
        $specimen->price = '12.5';
        $specimen->meta = ['size' => 'L', 'codes' => [1, 2]];
        $specimen->grade = Grade::from('a');
        $specimen->quantity = 7;
        $specimen->ratio = 0.25;

        self::assertSame(0, $this->flush());
    }

    /**
     * @return iterable<string, array{\Closure(Specimen): void, \Closure(Specimen): mixed, mixed}>
     */
    public static function changes(): iterable
    {
        yield 'string' => [static function (Specimen $s): void { $s->label = 'second'; }, static fn (Specimen $s): string => $s->label, 'second'];
        yield 'text' => [static function (Specimen $s): void { $s->notes = 'other text'; }, static fn (Specimen $s): string => $s->notes, 'other text'];
        yield 'integer' => [static function (Specimen $s): void { $s->quantity = 8; }, static fn (Specimen $s): int => $s->quantity, 8];
        yield 'bigint' => [static function (Specimen $s): void { $s->big = 9_000_000_001; }, static fn (Specimen $s): int => $s->big, 9_000_000_001];
        yield 'smallint' => [static function (Specimen $s): void { $s->small = 4; }, static fn (Specimen $s): int => $s->small, 4];
        yield 'boolean' => [static function (Specimen $s): void { $s->active = false; }, static fn (Specimen $s): bool => $s->active, false];
        yield 'decimal' => [static function (Specimen $s): void { $s->price = '12.51'; }, static fn (Specimen $s): string => $s->price, '12.51'];
        yield 'float' => [static function (Specimen $s): void { $s->ratio = 0.5; }, static fn (Specimen $s): float => $s->ratio, 0.5];
        yield 'datetime_immutable' => [static function (Specimen $s): void { $s->createdAt = $s->createdAt->modify('+1 second'); }, static fn (Specimen $s): string => $s->createdAt->format('Y-m-d H:i:s'), '2026-10-28 08:00:01'];
        yield 'date_immutable' => [static function (Specimen $s): void { $s->day = $s->day->modify('+1 day'); }, static fn (Specimen $s): string => $s->day->format('Y-m-d'), '2026-10-29'];
        yield 'time_immutable' => [static function (Specimen $s): void { $s->hour = $s->hour->modify('+1 minute'); }, static fn (Specimen $s): string => $s->hour->format('H:i:s'), '08:31:00'];
        yield 'json, nested change' => [static function (Specimen $s): void {
            $codes = (array) $s->meta['codes'];
            $codes[] = 3;
            $s->meta['codes'] = $codes;
        }, static fn (Specimen $s): mixed => $s->meta['codes'], [1, 2, 3]];
        yield 'uuid' => [static function (Specimen $s): void { $s->reference = '0192f5c4-6f3a-7b1c-9d2e-3f4a5b6c7d8f'; }, static fn (Specimen $s): string => $s->reference, '0192f5c4-6f3a-7b1c-9d2e-3f4a5b6c7d8f'];
        yield 'enum' => [static function (Specimen $s): void { $s->grade = Grade::B; }, static fn (Specimen $s): Grade => $s->grade, Grade::B];
        yield 'null to value' => [static function (Specimen $s): void { $s->comment = 'now set'; }, static fn (Specimen $s): ?string => $s->comment, 'now set'];
    }

    /**
     * @param \Closure(Specimen): void  $change
     * @param \Closure(Specimen): mixed $read
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('changes')]
    public function testEachChangeIsWrittenOnce(\Closure $change, \Closure $read, mixed $expected): void
    {
        $change($this->load());

        self::assertSame(1, $this->flush());
        self::assertSame(0, $this->flush(), 'the snapshot follows the written value');
        self::assertEquals($expected, $read($this->load()));
        self::assertSame(0, $this->flush(), 'read back, the new value is not a change');
    }

    // refresh() relit la ligne : rien à écrire ensuite
    public function testRefreshedEntityWritesNothing(): void
    {
        $specimen = $this->load();
        $specimen->label = 'changed then refreshed';
        $this->em->refresh($specimen);

        self::assertSame('first', $specimen->label);
        self::assertSame(0, $this->flush());
    }
}
