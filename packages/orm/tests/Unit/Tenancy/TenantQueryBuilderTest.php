<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Client_;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency_;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Invoice_;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Rate_;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Tag_;

/**
 * Scénarios de référence du filtre de tenant dans le QueryBuilder typé (phase 8, étape 2),
 * écrits avant le code, sans base : la condition de la racine dans le WHERE, celle d'une entité
 * jointe dans son ON pour qu'un LEFT JOIN reste un LEFT JOIN (RG-08.2, ADR 0014).
 * Le tenant courant est 1.
 */
final class TenantQueryBuilderTest extends TestCase
{
    // la colonne du tenant est lue : l'UoW garde le tenant de chaque entité (RG-08.6)
    private const string INVOICE = 'SELECT "i"."id", "i"."number", "i"."amount", "i"."client_id", "i"."currency_id", "i"."tenant_id" FROM "tenant_invoice" "i"';

    private StackTenantContext $context;

    private EntityManager $em;

    protected function setUp(): void
    {
        $this->context = new StackTenantContext(1);
        // la connexion SQLite n'est jamais ouverte
        $this->em = new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'), tenantContext: $this->context);
    }

    // scénario 15 : la racine
    public function testRootIsFiltered(): void
    {
        $qb = $this->em->query(Invoice_::as('i'));

        self::assertSame(self::INVOICE . ' WHERE "i"."tenant_id" = :prism_1', $qb->toSql());
        self::assertSame(['prism_1' => 1], $qb->getParameters());
    }

    // scénario 16 : la condition de l'appelant d'abord, celle du tenant ensuite
    public function testUserConditionIsKept(): void
    {
        $i = Invoice_::as('i');

        self::assertSame(self::INVOICE . ' WHERE ("i"."number" = :prism_1) AND ("i"."tenant_id" = :prism_2)', $this->em->query($i)->where($i->number->eq('F-1'))->toSql());
    }

    // scénarios 17 et 18 : condition dans le ON, jointure interne comme externe
    public function testJoinedEntityIsFilteredInItsOn(): void
    {
        $i = Invoice_::as('i');
        $c = $i->client->as('c');

        self::assertSame(self::INVOICE . ' INNER JOIN "tenant_client" "c" ON ("c"."id" = "i"."client_id") AND ("c"."tenant_id" = :prism_1) WHERE "i"."tenant_id" = :prism_2', $this->em->query($i)->join($c)->toSql());
        self::assertSame(self::INVOICE . ' LEFT JOIN "tenant_client" "c" ON ("c"."id" = "i"."client_id") AND ("c"."tenant_id" = :prism_1) WHERE "i"."tenant_id" = :prism_2', $this->em->query($i)->leftJoin($c)->toSql());
    }

    // une entité non tenantée jointe n'a pas de condition
    public function testJoinedEntityWithoutTenant(): void
    {
        $i = Invoice_::as('i');

        self::assertSame(self::INVOICE . ' LEFT JOIN "tenant_currency" "cu" ON "cu"."id" = "i"."currency_id" WHERE "i"."tenant_id" = :prism_1', $this->em->query($i)->leftJoin($i->currency->as('cu'))->toSql());
    }

    // scénarios 12 et 19 : collections ; le pivot n'a pas de tenant, sa cible est filtrée
    public function testCollectionsAreFiltered(): void
    {
        $i = Invoice_::as('i');

        self::assertSame(
            self::INVOICE . ' INNER JOIN "tenant_invoice_tag" "i__tags" ON "i__tags"."invoice_id" = "i"."id" INNER JOIN "tenant_tag" "t" ON ("t"."id" = "i__tags"."tag_id") AND ("t"."tenant_id" = :prism_1) WHERE "i"."tenant_id" = :prism_2',
            $this->em->query($i)->join($i->tags->as('t'))->toSql(),
        );
        self::assertStringContainsString(
            'LEFT JOIN "tenant_line" "l" ON ("l"."invoice_id" = "i"."id") AND ("l"."tenant_id" = :prism_1)',
            $this->em->query($i)->leftFetchJoin($i->lines->as('l'))->toSql(),
        );
    }

    // scénario 21 : une projection groupée ne compte que les lignes du tenant
    public function testProjectionIsFiltered(): void
    {
        $i = Invoice_::as('i');
        $c = $i->client->as('c');
        $projection = $this->em->query($i)->join($c)->groupBy($c->name)->selectInto(Fixtures\AmountByClient::class, client: $c->name, total: $i->amount->sum());

        self::assertSame(
            'SELECT "c"."name" AS "client", SUM("i"."amount") AS "total" FROM "tenant_invoice" "i" INNER JOIN "tenant_client" "c" ON ("c"."id" = "i"."client_id") AND ("c"."tenant_id" = :prism_1) WHERE "i"."tenant_id" = :prism_2 GROUP BY "c"."name"',
            $projection->toSql(),
        );
    }

    // scénario 31 : lignes partagées lues par le tenant
    public function testGlobalRowsAreRead(): void
    {
        self::assertStringEndsWith('WHERE ("r"."tenant_id" = :prism_1) OR ("r"."tenant_id" IS NULL)', $this->em->query(Rate_::as('r'))->toSql());
    }

    // scénario 32 : entité non tenantée
    public function testEntityWithoutTenantIsNotFiltered(): void
    {
        self::assertSame('SELECT "cu"."id", "cu"."code" FROM "tenant_currency" "cu"', $this->em->query(Currency_::as('cu'))->toSql());
    }

    // scénario 27 : fail-closed dès la compilation
    public function testMissingTenantFailsClosed(): void
    {
        $this->context->setTenantId(null);

        $this->expectException(MissingTenantException::class);
        $this->expectExceptionMessage('No current tenant for the #[Tenanted] entity ' . Fixtures\Client::class);

        $this->em->query(Client_::as('c'))->toSql();
    }

    // scénario 30 : le mode système ne filtre rien
    public function testSystemModeIsNotFiltered(): void
    {
        $i = Invoice_::as('i');

        self::assertSame(
            self::INVOICE . ' LEFT JOIN "tenant_line" "l" ON "l"."invoice_id" = "i"."id"',
            $this->context->runAsSystem(fn (): string => $this->em->query($i)->leftJoin($i->lines->as('l'))->toSql()),
        );
        self::assertSame('SELECT "t"."id", "t"."name", "t"."tenant_id" FROM "tenant_tag" "t"', $this->context->runAsSystem(fn (): string => $this->em->query(Tag_::as('t'))->toSql()));
    }
}
