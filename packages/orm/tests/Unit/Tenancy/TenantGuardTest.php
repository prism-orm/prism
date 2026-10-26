<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Tests\Support\TestLogger;
use Prism\Orm\Exception\UnfilteredTenantQueryException;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantGuard;
use Prism\Orm\Tenancy\TenantGuardMode;

/**
 * Garde du SQL brut (RG-08.7, ADR 0014) : une condition sur la colonne du tenant pour chaque
 * table tenantée lue ou écrite ; en cas de doute, refus. Tables tenantées : celles des fixtures
 * de tests/Unit/Tenancy (tenant_invoice, tenant_client…), tenant_currency ne l'est pas.
 */
final class TenantGuardTest extends TestCase
{
    private StackTenantContext $context;

    protected function setUp(): void
    {
        $this->context = new StackTenantContext(1);
    }

    private function guard(TenantGuardMode $mode = TenantGuardMode::Exception, ?TestLogger $logger = null): TenantGuard
    {
        return new TenantGuard(new MetadataFactory(new AttributeDriver([__DIR__ . '/Fixtures'])), $this->context, $mode, $logger ?? new TestLogger());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'condition' => ['SELECT id FROM tenant_invoice WHERE tenant_id = ?'];
        yield 'quoted identifiers' => ['SELECT "i"."id" FROM "tenant_invoice" "i" WHERE "i"."tenant_id" = :t'];
        yield 'backticks' => ['SELECT `i`.`id` FROM `tenant_invoice` `i` WHERE `i`.`tenant_id` = ?'];
        yield 'joined table in its ON' => ['SELECT i.id FROM tenant_invoice AS i JOIN tenant_client c ON c.id = i.client_id AND c.tenant_id = :t WHERE i.tenant_id = :t'];
        yield 'joined table in the WHERE' => ['SELECT i.id FROM tenant_invoice i LEFT JOIN tenant_client c ON c.id = i.client_id WHERE i.tenant_id = 1 AND c.tenant_id = 1'];
        yield 'every OR branch' => ['SELECT r.id FROM tenant_rate r WHERE (r.tenant_id = ?) OR (r.tenant_id IS NULL)'];
        yield 'schema-qualified table' => ['SELECT id FROM public.tenant_invoice WHERE tenant_invoice.tenant_id = ?'];
        yield 'BETWEEN … AND' => ['SELECT i.id FROM tenant_invoice i WHERE i.amount BETWEEN 1 AND 5 AND i.tenant_id = ?'];
        yield 'group, order, limit' => ['SELECT COUNT(*) FROM tenant_invoice WHERE tenant_id = ? GROUP BY amount ORDER BY amount LIMIT 5'];
        yield 'lock' => ['SELECT id FROM tenant_invoice WHERE tenant_id = ? FOR UPDATE'];
        yield 'LEFT() is a function' => ['SELECT LEFT(i.number, 2) FROM tenant_invoice i WHERE i.tenant_id = 1'];
        yield 'table without tenant' => ['SELECT code FROM tenant_currency'];
        yield 'subquery without tenant' => ['SELECT id FROM tenant_currency WHERE code IN (SELECT code FROM tenant_currency)'];
        yield 'names in strings and comments' => ["SELECT code FROM tenant_currency WHERE code = 'tenant_invoice' -- tenant_invoice\n /* tenant_invoice */"];
        yield 'update' => ['UPDATE tenant_invoice SET amount = 1 WHERE id = ? AND tenant_id = ?'];
        yield 'update moving a row' => ['UPDATE tenant_invoice SET tenant_id = 2 WHERE id = 3 AND tenant_id = 1'];
        yield 'update … from' => ['UPDATE tenant_invoice i SET amount = 0 FROM tenant_client c WHERE c.id = i.client_id AND i.tenant_id = 1 AND c.tenant_id = 1'];
        yield 'delete' => ['DELETE FROM tenant_invoice WHERE tenant_id = ? AND id = ?'];
        yield 'insert with the column' => ['INSERT INTO tenant_invoice (number, tenant_id) VALUES (?, ?)'];
        yield 'insert of every column' => ["INSERT INTO tenant_invoice VALUES (1, 'F-1', 10, NULL, NULL, 1)"];
        yield 'insert … set' => ['INSERT INTO tenant_invoice SET number = ?, tenant_id = ?'];
        yield 'DDL' => ['DROP TABLE tenant_invoice'];
        yield 'trailing semicolon' => ['SELECT id FROM tenant_invoice WHERE tenant_id = 1;'];
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $sql): void
    {
        $this->guard()->guard($sql);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        $unfiltered = 'The query reads or writes the tenanted table "%s" without a condition on "tenant_id"';
        $unparsable = 'The query uses the tenanted table "%s" in a form the TenantGuard cannot check';

        yield 'no condition' => ['SELECT id FROM tenant_invoice', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'OR bypass' => ['SELECT i.id FROM tenant_invoice i WHERE i.amount = 1 OR i.tenant_id = 2', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'OR branch without condition' => ['SELECT i.id FROM tenant_invoice i WHERE (i.tenant_id = 1 OR i.amount > 0)', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'joined table without condition' => ['SELECT i.id FROM tenant_invoice i JOIN tenant_client c ON c.id = i.client_id WHERE i.tenant_id = ?', \sprintf($unfiltered, 'tenant_client')];
        yield 'unqualified with two tables' => ['SELECT i.id FROM tenant_invoice i, tenant_currency c WHERE tenant_id = ?', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'another qualifier' => ['SELECT i.id FROM tenant_invoice i WHERE c.tenant_id = ?', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'another column' => ['SELECT id FROM tenant_invoice WHERE tenant_id_old = 1', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'update moving a row without condition' => ['UPDATE tenant_invoice SET tenant_id = 2 WHERE id = 3', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'delete' => ['DELETE FROM tenant_invoice WHERE id = 1', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'insert without the column' => ['INSERT INTO tenant_invoice (number) VALUES (?)', \sprintf($unfiltered, 'tenant_invoice')];
        yield 'insert of default values' => ['INSERT INTO tenant_client DEFAULT VALUES', \sprintf($unfiltered, 'tenant_client')];
        yield 'truncate' => ['TRUNCATE TABLE tenant_invoice', 'TRUNCATE empties the tenanted table "tenant_invoice" for every tenant'];
        yield 'subquery' => ['SELECT id FROM tenant_currency WHERE id IN (SELECT currency_id FROM tenant_invoice WHERE tenant_id = 1)', \sprintf($unparsable, 'tenant_invoice')];
        yield 'WITH' => ['WITH t AS (SELECT id FROM tenant_invoice) SELECT id FROM t', \sprintf($unparsable, 'tenant_invoice')];
        yield 'UNION' => ['SELECT id FROM tenant_invoice WHERE tenant_id = 1 UNION SELECT id FROM tenant_client WHERE tenant_id = 1', \sprintf($unparsable, 'tenant_invoice')];
        yield 'insert … select' => ['INSERT INTO tenant_currency (code) SELECT number FROM tenant_invoice WHERE tenant_id = 1', \sprintf($unparsable, 'tenant_invoice')];
        yield 'several statements' => ['SELECT 1; DELETE FROM tenant_invoice', \sprintf($unparsable, 'tenant_invoice')];
        yield 'multi-table delete' => ['DELETE i FROM tenant_invoice i WHERE i.tenant_id = 1', \sprintf($unparsable, 'tenant_invoice')];
        yield 'parenthesized join' => ['SELECT 1 FROM (tenant_invoice i JOIN tenant_client c ON c.id = i.client_id) WHERE i.tenant_id = 1 AND c.tenant_id = 1', \sprintf($unparsable, 'tenant_invoice')];
        // découpage incertain : la condition pourrait être un commentaire ou une chaîne
        yield 'MySQL # comment' => ['SELECT id FROM tenant_invoice # WHERE tenant_id = 1', \sprintf($unparsable, 'tenant_invoice')];
        yield '--x comment' => ['SELECT id FROM tenant_invoice --WHERE tenant_id = 1', \sprintf($unparsable, 'tenant_invoice')];
        yield 'MySQL executable comment' => ['SELECT id FROM tenant_invoice /*! JOIN tenant_client c */ WHERE tenant_id = 1', \sprintf($unparsable, 'tenant_invoice')];
        yield 'backslash in a string' => ["SELECT id FROM tenant_invoice WHERE number = 'a\\' OR tenant_id = 1 OR 'x' = 'x'", \sprintf($unparsable, 'tenant_invoice')];
        yield 'dollar quoting' => ['SELECT $$tenant_invoice$$', \sprintf($unparsable, 'tenant_invoice')];
    }

    #[DataProvider('refused')]
    public function testRefused(string $sql, string $message): void
    {
        $this->expectException(UnfilteredTenantQueryException::class);
        $this->expectExceptionMessage($message);

        $this->guard()->guard($sql);
    }

    // chaque table refusée l'est une fois, avec la requête dans le message
    public function testMessageHoldsTheQuery(): void
    {
        try {
            $this->guard()->guard('SELECT id FROM tenant_invoice');
            self::fail('no exception');
        } catch (UnfilteredTenantQueryException $e) {
            self::assertSame('The query reads or writes the tenanted table "tenant_invoice" without a condition on "tenant_id": SELECT id FROM tenant_invoice', $e->getMessage());
        }
    }

    // RG-08.5 : le mode système n'est pas gardé
    public function testSystemModeIsNotGuarded(): void
    {
        $guard = $this->guard();

        $this->context->runAsSystem(static fn () => $guard->guard('DELETE FROM tenant_invoice'));

        $this->expectException(UnfilteredTenantQueryException::class);
        $guard->guard('DELETE FROM tenant_invoice');
    }

    // le mode log ne bloque pas : un log critique par table, à chaque exécution (verdict en cache)
    public function testLogModeLogsEachViolation(): void
    {
        $logger = new TestLogger();
        $guard = $this->guard(TenantGuardMode::Log, $logger);
        $sql = 'SELECT i.id FROM tenant_invoice i JOIN tenant_client c ON c.id = i.client_id';

        $guard->guard($sql);
        $guard->guard($sql);

        self::assertSame(['critical', 'critical', 'critical', 'critical'], array_column($logger->records, 'level'));
        self::assertStringContainsString('"tenant_invoice"', $logger->records[0]['message']);
        self::assertStringContainsString('"tenant_client"', $logger->records[1]['message']);
        self::assertSame(['sql' => $sql], $logger->records[0]['context']);
    }

    // sans tenant, la garde s'applique aussi (RG-08.4)
    public function testGuardedWithoutTenant(): void
    {
        $this->context->setTenantId(null);

        $this->expectException(UnfilteredTenantQueryException::class);

        $this->guard()->guard('SELECT id FROM tenant_invoice');
    }
}
