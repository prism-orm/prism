<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Exception\QueryFailedException;
use Prism\Orm\EntityManager;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Link;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Page;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Page_;

/**
 * #[SoftDeletable] sur les 4 bases (RG-08.14, RG-08.15, ADR 0015) : les lignes supprimées sont
 * exclues des lectures, réintégrées par withDeleted(), atteintes par une référence ; l'unicité ne
 * porte que sur les lignes non supprimées.
 */
final class SoftDeleteTest extends MappingSchemaTestCase
{
    private EntityManager $em;

    protected static function entities(): array
    {
        return [Page::class, Link::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = new EntityManager($this->connection);
    }

    /**
     * home (supprimée, avec un lien) et about.
     *
     * @return array{Page, Page, Link}
     */
    private function seed(): array
    {
        $home = new Page('home');
        $about = new Page('about');
        $link = new Link($home, 'https://example.com');
        foreach ([$home, $about, $link] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->remove($home);
        // le lien suit sa page par la cascade : il est restauré pour lire la référence
        $this->em->flush();
        $this->em->restore($link);
        $this->em->flush();
        $this->em->clear();

        return [$home, $about, $link];
    }

    // RG-08.14 : find, findBy, count, QueryBuilder excluent les lignes supprimées
    public function testDeletedRowsAreExcluded(): void
    {
        [$home, $about] = $this->seed();
        $pages = $this->em->getRepository(Page::class);

        self::assertNull($this->em->find(Page::class, $home->id));
        self::assertSame(['about'], array_map(static fn (Page $p): string => $p->slug, $pages->findBy([])));
        self::assertSame(1, $pages->count());
        self::assertSame([$about->id], array_map(static fn (Page $p): ?int => $p->id, $this->em->query(Page_::as('p'))->getResult()));
    }

    public function testWithDeletedIncludesThem(): void
    {
        $this->seed();
        $p = Page_::as('p');

        self::assertSame(['about', 'home'], array_map(static fn (Page $page): string => $page->slug, $this->em->query($p)->withDeleted()->orderBy($p->slug->asc())->getResult()));
    }

    // ADR 0015 : une référence vers une page supprimée reste lisible
    public function testReferenceToADeletedRowLoads(): void
    {
        [, , $link] = $this->seed();

        $read = $this->em->find(Link::class, $link->id);

        self::assertNotNull($read);
        self::assertSame('home', $read->page->slug);
        self::assertNotNull($read->page->deletedAt);
    }

    public function testRestoreAndHardDelete(): void
    {
        [$home] = $this->seed();
        $p = Page_::as('p');
        $deleted = $this->em->query($p)->withDeleted()->where($p->slug->eq('home'))->getOneOrNull();
        self::assertNotNull($deleted);

        $this->em->restore($deleted);
        $this->em->flush();
        $this->em->clear();
        self::assertSame('home', $this->em->find(Page::class, $home->id)?->slug);

        $about = $this->em->getRepository(Page::class)->findOneBy(['slug' => 'about']);
        self::assertNotNull($about);
        $this->em->remove($about, hardDelete: true);
        $this->em->flush();
        self::assertCount(1, $this->em->query($p)->withDeleted()->getResult());
    }

    public function testRestoreRestoresSoftDeletedCascadeChildrenAfterReload(): void
    {
        $page = new Page('home');
        $link = new Link($page, 'https://example.com');
        $this->em->persist($page);
        $this->em->persist($link);
        $this->em->flush();
        $this->em->remove($page);
        $this->em->flush();
        $pageId = $page->id;
        $linkId = $link->id;
        self::assertNotNull($pageId);
        self::assertNotNull($linkId);
        $this->em->clear();

        $p = Page_::as('p');
        $deleted = $this->em->query($p)->withDeleted()->where($p->id->eq($pageId))->getOneOrNull();
        self::assertNotNull($deleted);
        $this->em->restore($deleted);
        $this->em->flush();
        $this->em->clear();

        self::assertNotNull($this->em->find(Page::class, $pageId));
        self::assertNotNull($this->em->find(Link::class, $linkId));
    }

    // RG-08.15 : le slug se répète parmi les pages supprimées, jamais parmi les autres
    public function testUniquenessWithSoftDelete(): void
    {
        foreach ([1, 2] as $_) {
            $page = new Page('faq');
            $this->em->persist($page);
            $this->em->flush();
            $this->em->remove($page);
            $this->em->flush();
        }
        $this->em->persist(new Page('faq'));
        $this->em->flush();
        $this->em->clear();
        self::assertCount(3, $this->em->query(Page_::as('p'))->withDeleted()->getResult());

        $this->expectException(QueryFailedException::class);
        $this->em->persist(new Page('faq'));
        $this->em->flush();
    }
}
