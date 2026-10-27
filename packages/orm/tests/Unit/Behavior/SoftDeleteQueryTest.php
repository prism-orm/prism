<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Behavior;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Schema\Index;
use Prism\Orm\Behavior\SoftDeleteFilter;
use Prism\Orm\EntityManager;
use Prism\Orm\Filter\FilterOperation;
use Prism\Orm\Filter\FilterTarget;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Link;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Link_;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Page;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Behavior\Page_;
use Prism\Orm\Tools\SchemaTool;

/**
 * #[SoftDeletable] sans base (RG-08.14, RG-08.15, ADR 0015) : la condition du filtre selon la
 * requête, le SQL du QueryBuilder typé, l'unicité partielle du schéma.
 */
final class SoftDeleteQueryTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        // la connexion SQLite n'est jamais ouverte
        $this->em = new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'));
    }

    // lecture seulement : une écriture (restore) et une référence atteignent la ligne supprimée
    public function testFilterAppliesToReadsOnly(): void
    {
        $qb = $this->em->getConnection()->createQueryBuilder();
        $metadata = new AttributeDriver()->loadMetadata(Page::class);
        $filter = new SoftDeleteFilter();

        $read = $filter->condition($metadata, new FilterTarget($qb, 'p'));
        self::assertNotNull($read);
        self::assertStringEndsWith('WHERE "p"."deleted_at" IS NULL', $qb->select('*')->from('page', 'p')->where($read)->toSql());
        self::assertNull($filter->condition($metadata, new FilterTarget($qb, 'p', FilterOperation::Write)));
        self::assertNull($filter->condition($metadata, new FilterTarget($qb, 'p', FilterOperation::Load)));
    }

    public function testQueryExcludesDeletedRowsUnlessAsked(): void
    {
        $p = Page_::as('p');

        self::assertSame('SELECT "p"."id", "p"."deleted_at", "p"."slug" FROM "page" "p" WHERE "p"."deleted_at" IS NULL', $this->em->query($p)->toSql());
        self::assertSame('SELECT "p"."id", "p"."deleted_at", "p"."slug" FROM "page" "p"', $this->em->query($p)->withDeleted()->toSql());
    }

    // une entité jointe : la condition dans son ON, levée aussi par withDeleted()
    public function testJoinedEntity(): void
    {
        $l = Link_::as('l');
        $p = $l->page->as('p');

        self::assertStringContainsString('INNER JOIN "page" "p" ON ("p"."id" = "l"."page_id") AND ("p"."deleted_at" IS NULL)', $this->em->query($l)->join($p)->toSql());
        self::assertStringContainsString('INNER JOIN "page" "p" ON "p"."id" = "l"."page_id"', $this->em->query($l)->withDeleted()->join($p)->toSql());
    }

    // le filtre se désactive pour tout l'EntityManager ; celui du tenant, non
    public function testFilterCanBeDisabled(): void
    {
        $this->em->getFilters()->disable(SoftDeleteFilter::NAME);

        self::assertStringEndsWith('FROM "page" "p"', $this->em->query(Page_::as('p'))->toSql());
    }

    // RG-08.15 : l'unicité ne couvre que les lignes non supprimées
    public function testUniqueConstraintIsPartial(): void
    {
        $table = new SchemaTool(new PostgreSqlPlatform('16'))->fromMetadata([new AttributeDriver()->loadMetadata(Page::class), new AttributeDriver()->loadMetadata(Link::class)])->getTable('page');
        $unique = array_values(array_filter($table->getIndexes(), static fn (Index $i): bool => $i->unique))[0] ?? null;

        self::assertNotNull($unique);
        self::assertSame([['slug'], 'deleted_at'], [$unique->columns, $unique->whereNull]);
    }
}
