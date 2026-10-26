<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Orm\EntityManager;
use Prism\Orm\Tests\Support\FixedActor;
use Prism\Orm\Tests\Support\FrozenClock;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Memo;

/**
 * #[Timestampable] et #[Blameable] sur les 4 bases (RG-08.13, RG-08.16) : l'heure UTC et
 * l'auteur relus tels qu'écrits ; updatedAt ne bouge que sur un vrai changement.
 */
final class BehaviorsTest extends MappingSchemaTestCase
{
    protected static function entities(): array
    {
        return [Memo::class];
    }

    public function testTimestampsAndAuthorsRoundTrip(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-26 08:30:00', new \DateTimeZone('UTC')));
        $actor = new FixedActor('ada');
        $em = new EntityManager($this->connection, clock: $clock, actorProvider: $actor);

        $em->persist($memo = new Memo('a'));
        $em->flush();
        $clock->advance('+1 day');
        $actor->actor = 'bob';
        $em->flush();
        $memo->title = 'b';
        $em->flush();
        $em->clear();

        $read = $em->find(Memo::class, $memo->id);
        self::assertNotNull($read);
        self::assertSame(['2026-10-26 08:30:00', '2026-10-27 08:30:00'], [$read->createdAt->format('Y-m-d H:i:s'), $read->updatedAt?->format('Y-m-d H:i:s')]);
        self::assertSame(['ada', 'bob'], [$read->createdBy, $read->updatedBy]);
    }
}
