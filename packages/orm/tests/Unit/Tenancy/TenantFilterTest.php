<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Orm\Exception\MissingTenantException;
use Prism\Orm\Filter\FilterOperation;
use Prism\Orm\Filter\FilterTarget;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantFilter;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Currency;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Rate;
use Prism\Orm\Tests\Unit\Tools\Fixtures\Ledger;

/**
 * Scénarios de référence du TenantFilter (phase 8, étape 2), écrits avant son code : la
 * condition ajoutée à une table d'entité, selon le tenant courant et la nature de la requête.
 */
final class TenantFilterTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        // la connexion SQLite n'est jamais ouverte
        $this->qb = DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0')->createQueryBuilder();
    }

    /**
     * La condition rendue en SQL, ses valeurs et leurs types ; null : aucun filtre.
     *
     * @param class-string $class
     *
     * @return array{string, list<mixed>, list<mixed>}|null
     */
    private function condition(StackTenantContext $context, string $class, FilterOperation $operation = FilterOperation::Read, ?string $alias = 't', string $tenantType = 'integer'): ?array
    {
        $metadata = new AttributeDriver(tenantType: $tenantType)->loadMetadata($class);
        $condition = new TenantFilter($context)->condition($metadata, new FilterTarget($this->qb, $alias, $operation));
        if (null === $condition) {
            return null;
        }
        $qb = $this->qb->select('*')->from($metadata->tableName, $alias)->where($condition);
        $sql = $qb->toSql();

        return [substr($sql, (int) strpos($sql, ' WHERE ') + 7), array_values($qb->getParameters()), array_values($qb->getParameterTypes())];
    }

    // RG-08.2 : lecture et écriture filtrées par le tenant courant, valeur liée et typée
    public function testTenantedEntityIsFilteredByTheCurrentTenant(): void
    {
        $context = new StackTenantContext(7);

        self::assertSame(['"t"."tenant_id" = :prism_1', [7], ['integer']], $this->condition($context, Invoice::class));
        self::assertSame(['"t"."tenant_id" = :prism_1', [7], ['integer']], $this->condition($context, Invoice::class, FilterOperation::Write));
    }

    // le persister écrit ses requêtes sans alias
    public function testTableWithoutAlias(): void
    {
        self::assertSame(['"tenant_id" = :prism_1', [7], ['integer']], $this->condition(new StackTenantContext(7), Invoice::class, alias: null));
    }

    // ADR 0014 : le type de la colonne est celui de l'EntityManager
    public function testUuidTenant(): void
    {
        $id = '0192b9a0-7c3e-7d2a-9f1e-2b6c4d8e0a11';

        self::assertSame(['"t"."tenant_id" = :prism_1', [$id], ['uuid']], $this->condition(new StackTenantContext($id), Invoice::class, tenantType: 'uuid'));
    }

    // #[Tenanted(column:)] porté par une propriété
    public function testNamedTenantColumn(): void
    {
        self::assertSame(['"t"."company_id" = :prism_1', [7], ['integer']], $this->condition(new StackTenantContext(7), Ledger::class));
    }

    public function testEntityWithoutTenantIsNotFiltered(): void
    {
        self::assertNull($this->condition(new StackTenantContext(7), Currency::class));
        self::assertNull($this->condition(new StackTenantContext(), Currency::class), 'even without tenant');
    }

    // ADR 0014 : les lignes partagées sont lues par tous les tenants, écrites en mode système seulement
    public function testGlobalRowsAreReadButNeverWrittenByATenant(): void
    {
        $context = new StackTenantContext(7);

        self::assertSame(['("t"."tenant_id" = :prism_1) OR ("t"."tenant_id" IS NULL)', [7], ['integer']], $this->condition($context, Rate::class));
        self::assertSame(['"t"."tenant_id" = :prism_1', [7], ['integer']], $this->condition($context, Rate::class, FilterOperation::Write));
    }

    // RG-08.4 : fail-closed, en lecture comme en écriture
    public function testMissingTenantFailsClosed(): void
    {
        foreach ([FilterOperation::Read, FilterOperation::Write] as $operation) {
            foreach ([Invoice::class, Rate::class] as $class) {
                try {
                    $this->condition(new StackTenantContext(), $class, $operation);
                    self::fail(\sprintf('%s, %s: no exception', $class, $operation->name));
                } catch (MissingTenantException $e) {
                    self::assertSame('No current tenant for the #[Tenanted] entity ' . $class . ': set one in the TenantContext, or use runAs() or runAsSystem() explicitly.', $e->getMessage());
                }
            }
        }
    }

    // RG-08.5 : le mode système voit tous les tenants
    public function testSystemModeIsNotFiltered(): void
    {
        $context = new StackTenantContext(7);

        self::assertNull($context->runAsSystem(fn (): ?array => $this->condition($context, Invoice::class)));
        self::assertNull($context->runAsSystem(fn (): ?array => $this->condition($context, Rate::class, FilterOperation::Write)));
    }

    // runAs() change le tenant de la condition, puis le rétablit
    public function testRunAsFiltersByAnotherTenant(): void
    {
        $context = new StackTenantContext(7);

        self::assertSame([8], $context->runAs(8, fn (): array => $this->condition($context, Invoice::class)[1] ?? []));
        self::assertSame([7], $this->condition($context, Invoice::class)[1] ?? []);
    }
}
