<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\FixedActor;
use Prism\Orm\Tests\Support\FrozenClock;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Memo;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * #[Timestampable] et #[Blameable] (RG-08.13, RG-08.16, ADR 0015) : l'heure UTC et l'auteur à
 * l'INSERT, puis à chaque UPDATE qui change un autre champ.
 */
final class UnitOfWorkBehaviorsTest extends TestCase
{
    private InMemoryStorage $storage;

    private FrozenClock $clock;

    private FixedActor $actor;

    private UnitOfWork $uow;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        // une horloge hors UTC (Paris, UTC+1 en hiver) : l'ORM écrit en UTC quand même
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-26 10:00:00', new \DateTimeZone('Europe/Paris')));
        $this->actor = new FixedActor('ada');
        $this->uow = new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), clock: $this->clock, actors: $this->actor);
    }

    private function insert(Memo $memo): Memo
    {
        $this->uow->persist($memo);
        $this->uow->commit();

        return $memo;
    }

    // RG-08.13, RG-08.16 : à l'INSERT, created et updated reçoivent l'heure UTC et l'auteur
    public function testInsertStampsTimeAndActor(): void
    {
        $memo = $this->insert(new Memo('a'));

        self::assertSame('2026-10-26T09:00:00+00:00', $memo->createdAt->format(\DATE_ATOM));
        self::assertEquals($memo->createdAt, $memo->updatedAt);
        self::assertSame(['ada', 'ada'], [$memo->createdBy, $memo->updatedBy]);
        self::assertSame('ada', $this->storage->row('memo', (int) $memo->id)['created_by']);
    }

    // une valeur posée par l'application (reprise de données) est gardée
    public function testExplicitValuesAreKept(): void
    {
        $memo = new Memo('a');
        $memo->createdAt = new \DateTimeImmutable('2020-01-01 00:00:00+00:00');
        $memo->createdBy = 'import';
        $this->insert($memo);

        self::assertSame('2020-01-01', $memo->createdAt->format('Y-m-d'));
        self::assertSame('import', $memo->createdBy);
        self::assertSame('ada', $memo->updatedBy);
    }

    // RG-08.13 : updatedAt et updatedBy suivent un changement d'un autre champ
    public function testUpdateStampsOnlyWhenAnotherFieldChanges(): void
    {
        $memo = $this->insert(new Memo('a'));
        $created = $memo->createdAt;
        $this->clock->advance('+1 hour');
        $this->actor->actor = 'bob';

        $this->uow->commit();
        self::assertEquals($created, $memo->updatedAt, 'nothing changed');
        self::assertSame([], $this->storage->operations('UPDATE'));

        $memo->title = 'b';
        $this->uow->commit();
        self::assertSame('2026-10-26T10:00:00+00:00', $memo->updatedAt?->format(\DATE_ATOM));
        self::assertSame(['ada', 'bob'], [$memo->createdBy, $memo->updatedBy]);
        self::assertEquals($created, $memo->createdAt, 'createdAt never changes');
        self::assertSame('bob', $this->storage->row('memo', (int) $memo->id)['updated_by']);
    }

    // la date posée à la main n'est pas un changement d'un autre champ : elle est écrite telle quelle
    public function testUpdatedAtSetByTheApplicationAlone(): void
    {
        $memo = $this->insert(new Memo('a'));
        $this->clock->advance('+1 hour');
        $memo->updatedAt = new \DateTimeImmutable('2030-01-01 00:00:00+00:00');

        $this->uow->commit();

        self::assertSame('2030-01-01', $memo->updatedAt->format('Y-m-d'));
        self::assertSame('ada', $memo->updatedBy);
    }

    // sans auteur connu (tâche, commande), les champs restent NULL
    public function testWithoutActor(): void
    {
        $this->actor->actor = null;
        $memo = $this->insert(new Memo('a'));

        self::assertNull($memo->createdBy);
        self::assertNull($this->storage->row('memo', (int) $memo->id)['created_by']);
    }

    // RG-03.6 : un flush annulé rétablit ce qui peut l'être
    public function testRollbackRestoresNullableFields(): void
    {
        $this->storage->failOnInsert(2);
        $first = new Memo('a');
        $this->uow->persist($first);
        $this->uow->persist(new Memo('b'));

        try {
            $this->uow->commit();
            self::fail('no exception');
        } catch (\RuntimeException) {
        }

        self::assertNull($first->updatedAt);
        self::assertNull($first->createdBy);
    }
}
