<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Dbal\Type\ConversionContext;
use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryStorage;
use Prism\Orm\Tests\Support\TestDispatcher;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Article;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\AuditLine;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Contact;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Document;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Note;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Ticket;
use Prism\Orm\UnitOfWork\EntityState;
use Prism\Orm\UnitOfWork\UnitOfWork;

/**
 * Compléments aux scénarios de référence, issus des mutants survivants d'Infection : chaque test
 * ferme un trou réel (plusieurs entités dans un flush, absence de dispatcher, SEQUENCE…).
 */
final class UnitOfWorkCompletenessTest extends TestCase
{
    private InMemoryStorage $storage;

    private TestDispatcher $events;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
        $this->events = new TestDispatcher();
    }

    private function uow(bool $withEvents = true): UnitOfWork
    {
        return new UnitOfWork(new MetadataFactory(new AttributeDriver()), $this->storage, new ConversionContext(new SqlitePlatform('3.46')), TypeRegistry::withDefaults(), $withEvents ? $this->events : null);
    }

    private static function article(string $title): Article
    {
        $article = new Article(strtolower($title));
        $article->title = $title;

        return $article;
    }

    public function testWorksWithoutEventDispatcher(): void
    {
        $uow = $this->uow(withEvents: false);
        $article = self::article('A');

        $uow->persist($article);
        $uow->commit();
        $article->title = 'B';
        $uow->commit();
        $uow->remove($article);
        $uow->commit();

        self::assertSame(['INSERT', 'UPDATE', 'DELETE'], array_column($this->storage->log, 0));
    }

    // tous les snapshots d'un flush sont mis à jour, pas seulement le premier
    public function testEveryEntityOfAFlushIsUpToDateAfterwards(): void
    {
        $uow = $this->uow();
        $a = self::article('A');
        $b = self::article('B');
        $uow->persist($a);
        $uow->persist($b);
        $uow->commit();

        self::assertSame($b, $uow->find(Article::class, $b->id));

        $a->title = 'A2';
        $b->title = 'B2';
        $uow->commit();
        $this->storage->log = [];

        $uow->commit();
        self::assertSame([], $this->storage->operations('UPDATE'), 'both snapshots were refreshed by the previous flush');
    }

    // RG-03.3 : deux identifiants, deux entités
    public function testDifferentIdsGiveDifferentEntities(): void
    {
        $uow = $this->uow();
        foreach ([1, 2] as $id) {
            $this->storage->seed('document', 'id', ['id' => $id, 'title' => 'Doc ' . $id, 'version' => 1]);
        }

        $first = $uow->find(Document::class, 1);
        $second = $uow->find(Document::class, 2);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
        self::assertSame(['Doc 1', 'Doc 2'], [$first->title, $second->title]);
        self::assertSame($second, $uow->find(Document::class, '2'), 'an id given as int or string is the same identity map key');
    }

    // RG-03.9 : la version d'une suppression réussie ne lève rien
    public function testVersionedEntityIsDeleted(): void
    {
        $uow = $this->uow();
        $document = new Document();
        $uow->persist($document);
        $uow->commit();

        $uow->remove($document);
        $uow->commit();

        self::assertSame(EntityState::New, $uow->getEntityState($document));
        self::assertSame([], $this->storage->tables['document']);
    }

    public function testNonCandidatesDoNotHideLaterChanges(): void
    {
        $uow = $this->uow();
        $line = new AuditLine();
        $note = new Note();
        $pending = self::article('Pending');
        $changed = self::article('Changed');
        foreach ([$line, $note, $changed] as $entity) {
            $uow->persist($entity);
        }
        $uow->commit();

        // devant l'entité modifiée : une entité readOnly, une EXPLICIT non persistée, une nouvelle
        $line->message = 'ignored';
        $note->body = 'ignored';
        $uow->persist($pending);
        $changed->title = 'Changed again';
        $this->storage->log = [];
        $uow->commit();

        self::assertSame([['article', ['title']]], $this->storage->operations('UPDATE'));
    }

    // RG-03.8 : un listener qui annule la modification n'empêche pas les suivantes
    public function testPreUpdateRevertingTheChangeSkipsOnlyThatEntity(): void
    {
        $uow = $this->uow();
        $reverted = self::article('Reverted');
        $kept = self::article('Kept');
        $uow->persist($reverted);
        $uow->persist($kept);
        $uow->commit();
        $this->events->listen(PreUpdateEvent::class, static function (PreUpdateEvent $event) use ($reverted): void {
            if ($event->entity === $reverted) {
                $reverted->title = 'Reverted';
            }
        });

        $reverted->title = 'Changed';
        $kept->title = 'Kept changed';
        $this->storage->log = [];
        $uow->commit();

        self::assertCount(1, $this->storage->operations('UPDATE'));
        self::assertSame('Kept changed', $this->storage->row('article', 2)['title']);
    }

    public function testChangeSetIsKeyedByFieldNotColumn(): void
    {
        $uow = $this->uow();
        $article = self::article('A');
        $uow->persist($article);
        $uow->commit();

        $article->publishedAt = new \DateTimeImmutable('2026-10-18 09:00:00');
        $article->title = 'B';
        $uow->commit();

        $event = $this->events->ofType(PreUpdateEvent::class)[0] ?? null;
        self::assertInstanceOf(PreUpdateEvent::class, $event);
        self::assertSame(['title', 'publishedAt'], array_keys($event->changeSet));
        self::assertNull($event->changeSet['publishedAt'][0]);
        self::assertEquals(new \DateTimeImmutable('2026-10-18 09:00:00'), $event->changeSet['publishedAt'][1]);
    }

    // RG-02.4 : SEQUENCE, identifiant écrit avant l'INSERT et rétabli par le rollback
    public function testSequenceIdentifierIsSetBeforeInsertAndResetOnRollback(): void
    {
        $uow = $this->uow();
        $ticket = new Ticket();
        $uow->persist($ticket);
        $uow->persist(self::article('Fails'));
        $this->storage->failOnInsert(2);

        try {
            $uow->commit();
            self::fail('Expected the simulated failure.');
        } catch (\RuntimeException) {
        }
        self::assertNull($ticket->id);

        $this->storage->failOnInsert(null);
        $uow->commit();
        self::assertSame(101, $ticket->id);
        self::assertSame(101, $this->storage->row('ticket', 101)['id'], 'the generated value is the inserted one');
    }

    // RG-03.5 : un champ d'embeddable modifié en place est détecté, seul
    public function testMutableEmbeddableFieldChangedInPlaceIsUpdated(): void
    {
        $uow = $this->uow();
        $ticket = new Ticket();
        $ticket->contact = $contact = new Contact();
        $contact->email = 'a@example.test';
        $uow->persist($ticket);
        $uow->commit();

        $contact->email = 'b@example.test';
        $this->storage->log = [];
        $uow->commit();

        self::assertSame([['ticket', ['contact_email']]], $this->storage->operations('UPDATE'));
    }

    // étape 8 : le snapshot est calculé depuis la ligne hydratée ; une ligne incomplète laisse
    // la propriété à sa valeur par défaut, relue telle quelle
    public function testIncompleteRowGivesNoSpuriousUpdate(): void
    {
        $uow = $this->uow();
        $this->storage->seed('document', 'id', ['id' => 1, 'version' => 1]);
        $document = $uow->find(Document::class, 1);
        self::assertNotNull($document);

        $uow->commit();
        self::assertSame([], $this->storage->operations('UPDATE'));

        $document->title = 'Now set';
        $uow->commit();
        self::assertSame([['document', ['title', 'version']]], $this->storage->operations('UPDATE'));
    }
}
