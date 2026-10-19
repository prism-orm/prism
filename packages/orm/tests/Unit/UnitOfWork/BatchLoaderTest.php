<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Metadata\AssociationMapping;
use Prism\Orm\Metadata\AssociationType;
use Prism\Orm\UnitOfWork\BatchLoader;
use Prism\Orm\UnitOfWork\Cohort;

/**
 * Décisions du BatchLoader, sans base : ce qui se charge ensemble (RG-04.10 à RG-04.14).
 */
final class BatchLoaderTest extends TestCase
{
    private static function relation(?int $batchSize = null, bool $batch = true): AssociationMapping
    {
        return new AssociationMapping(\stdClass::class, 'target', AssociationType::ManyToOne, \stdClass::class, joinColumn: 'target_id', batchSize: $batchSize, batch: $batch);
    }

    private static function collection(?int $batchSize = null, bool $batch = true): AssociationMapping
    {
        return new AssociationMapping(\stdClass::class, 'items', AssociationType::OneToMany, \stdClass::class, 'owner', batchSize: $batchSize, batch: $batch);
    }

    /**
     * Une cohorte de $count membres, chacun avec sa cible.
     *
     * @return array{Cohort, list<\stdClass>, list<\stdClass>} cohorte, membres, cibles
     */
    private static function cohort(BatchLoader $loader, int $count, AssociationMapping $association): array
    {
        $cohort = new Cohort();
        $members = [];
        $targets = [];
        for ($i = 0; $i < $count; ++$i) {
            $member = new \stdClass();
            $target = new \stdClass();
            $member->target = $target;
            $targets[] = $target;
            $loader->join($cohort, $member);
            $loader->referenced($target, $member, $association);
            $members[] = $member;
        }

        return [$cohort, $members, $targets];
    }

    private static function targetOf(object $member): mixed
    {
        return $member instanceof \stdClass ? ($member->target ?? null) : null;
    }

    public function testBatchSizeMustBePositive(): void
    {
        self::assertSame(100, new BatchLoader()->batchSize, 'RG-04.11');
        self::assertSame(1, new BatchLoader(1)->batchSize);

        $this->expectException(InvalidArgumentException::class);

        new BatchLoader(0);
    }

    // ---------------------------------------------------------------- cibles ToOne

    public function testTargetsOfTheCohortAreLoadedTogetherTargetFirst(): void
    {
        $loader = new BatchLoader();
        [, , $targets] = self::cohort($loader, 4, self::relation());

        $batch = $loader->targetsWith($targets[2], self::targetOf(...), static fn (): bool => true);

        self::assertSame([$targets[2], $targets[0], $targets[1], $targets[3]], $batch);
    }

    public function testDefaultBatchSize(): void
    {
        $loader = new BatchLoader(3);
        [, , $targets] = self::cohort($loader, 5, self::relation());

        self::assertCount(3, $loader->targetsWith($targets[0], self::targetOf(...), static fn (): bool => true));
    }

    public function testBatchSizeOfTheRelationWins(): void
    {
        $loader = new BatchLoader(3);
        [, , $targets] = self::cohort($loader, 5, self::relation(batchSize: 2));

        self::assertSame([$targets[0], $targets[1]], $loader->targetsWith($targets[0], self::targetOf(...), static fn (): bool => true));
    }

    public function testOnlyPendingDistinctTargetsAreAdded(): void
    {
        $loader = new BatchLoader();
        [, $members, $targets] = self::cohort($loader, 4, self::relation());
        $members[3]->target = $targets[1];
        $loaded = $targets[2];

        $batch = $loader->targetsWith($targets[0], self::targetOf(...), static fn (object $t): bool => $t !== $loaded);

        self::assertSame([$targets[0], $targets[1]], $batch, 'already loaded or already in the batch: skipped');
    }

    public function testMembersWithoutTargetAreSkipped(): void
    {
        $loader = new BatchLoader();
        [, $members, $targets] = self::cohort($loader, 3, self::relation());
        $members[1]->target = null;

        self::assertSame([$targets[0], $targets[2]], $loader->targetsWith($targets[0], self::targetOf(...), static fn (): bool => true));
    }

    // RG-04.13
    public function testBatchFalseLoadsAlone(): void
    {
        $loader = new BatchLoader();
        [, , $targets] = self::cohort($loader, 3, self::relation(batch: false));

        self::assertSame([$targets[1]], $loader->targetsWith($targets[1], self::targetOf(...), static fn (): bool => true));
    }

    public function testTargetWithoutOriginLoadsAlone(): void
    {
        $target = new \stdClass();

        self::assertSame([$target], new BatchLoader()->targetsWith($target, self::targetOf(...), static fn (): bool => true));
    }

    public function testReferenceFromAnEntityOutsideAnyCohortHasNoOrigin(): void
    {
        $loader = new BatchLoader();
        [, , $targets] = self::cohort($loader, 2, self::relation());
        $outsider = new \stdClass();
        $outsider->target = $targets[0];

        $loader->referenced($targets[0], $outsider, self::relation());

        self::assertCount(2, $loader->targetsWith($targets[0], self::targetOf(...), static fn (): bool => true), 'the known origin is kept');
    }

    public function testLastOriginWins(): void
    {
        $loader = new BatchLoader();
        [, , $targets] = self::cohort($loader, 3, self::relation());
        // la même cible, référencée ensuite depuis une cohorte de 2
        $other = new Cohort();
        $member = new \stdClass();
        $member->target = $targets[0];
        $sibling = new \stdClass();
        $sibling->target = $fresh = new \stdClass();
        $loader->join($other, $member);
        $loader->join($other, $sibling);
        $loader->referenced($targets[0], $member, self::relation());

        self::assertSame([$targets[0], $fresh], $loader->targetsWith($targets[0], self::targetOf(...), static fn (): bool => true));
    }

    // ---------------------------------------------------------------- collections (RG-04.12)

    public function testOwnersOfTheCohortLoadTheirCollectionTogether(): void
    {
        $loader = new BatchLoader();
        [, $members] = self::cohort($loader, 4, self::relation());
        $loaded = $members[3];

        $owners = $loader->ownersWith($members[1], self::collection(), static fn (object $m): bool => $m !== $loaded);

        self::assertSame([$members[1], $members[0], $members[2]], $owners);
    }

    public function testOwnersAreLimitedByTheBatchSize(): void
    {
        $loader = new BatchLoader(10);
        [, $members] = self::cohort($loader, 5, self::relation());

        self::assertSame([$members[0], $members[1]], $loader->ownersWith($members[0], self::collection(batchSize: 2), static fn (): bool => true));
    }

    public function testDefaultBatchSizeForCollections(): void
    {
        $loader = new BatchLoader(2);
        [, $members] = self::cohort($loader, 5, self::relation());

        self::assertCount(2, $loader->ownersWith($members[0], self::collection(), static fn (): bool => true));
    }

    public function testOwnerOutsideAnyCohortOrWithBatchFalseLoadsAlone(): void
    {
        $loader = new BatchLoader();
        [, $members] = self::cohort($loader, 3, self::relation());
        $outsider = new \stdClass();

        self::assertSame([$outsider], $loader->ownersWith($outsider, self::collection(), static fn (): bool => true));
        self::assertSame([$members[0]], $loader->ownersWith($members[0], self::collection(batch: false), static fn (): bool => true));
    }

    // ---------------------------------------------------------------- cohortes (RG-04.14)

    public function testJoiningAnotherCohortLeavesThePreviousOne(): void
    {
        $loader = new BatchLoader();
        [$first, $members] = self::cohort($loader, 2, self::relation());

        $loader->join(new Cohort(), $members[0]);

        self::assertSame([$members[1]], $first->members());
    }

    public function testForgottenEntityLeavesItsCohortAndItsOrigin(): void
    {
        $loader = new BatchLoader();
        [$cohort, $members, $targets] = self::cohort($loader, 3, self::relation());

        $loader->forget($members[0]);
        $loader->forget($targets[1]);

        self::assertSame([$members[1], $members[2]], $cohort->members());
        self::assertSame([$targets[1]], $loader->targetsWith($targets[1], self::targetOf(...), static fn (): bool => true), 'its origin is forgotten');
        self::assertSame([$members[1]], $loader->ownersWith($members[1], self::collection(), static fn (object $m): bool => $m === $members[1] || $m === $members[0]));
    }

    public function testClearForgetsEverything(): void
    {
        $loader = new BatchLoader();
        [, $members, $targets] = self::cohort($loader, 3, self::relation());

        $loader->clear();

        self::assertSame([$targets[0]], $loader->targetsWith($targets[0], self::targetOf(...), static fn (): bool => true));
        self::assertSame([$members[0]], $loader->ownersWith($members[0], self::collection(), static fn (): bool => true));
    }
}
