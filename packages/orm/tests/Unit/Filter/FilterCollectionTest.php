<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Dbal\Query\Expression;
use Prism\Dbal\Query\QueryBuilder;
use Prism\Orm\Exception\InvalidArgumentException;
use Prism\Orm\Filter\EntityFilter;
use Prism\Orm\Filter\FilterCollection;
use Prism\Orm\Filter\FilterTarget;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Tests\Unit\Tenancy\Fixtures\Invoice;

/**
 * Filtres d'un EntityManager (ADR 0014) : réunis par AND, désactivables sauf s'ils sont
 * verrouillés.
 */
final class FilterCollectionTest extends TestCase
{
    private QueryBuilder $qb;

    /** @var ClassMetadata<Invoice> */
    private ClassMetadata $invoice;

    protected function setUp(): void
    {
        // la connexion SQLite n'est jamais ouverte
        $this->qb = DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0')->createQueryBuilder();
        $this->invoice = new AttributeDriver()->loadMetadata(Invoice::class);
    }

    private static function filter(string $column, mixed $value): EntityFilter
    {
        return new class($column, $value) implements EntityFilter {
            public function __construct(private readonly string $column, private readonly mixed $value)
            {
            }

            public function condition(ClassMetadata $metadata, FilterTarget $target): ?Expression
            {
                return null === $this->value ? null : $target->expr()->eq($target->column($this->column), $this->value);
            }
        };
    }

    private function where(FilterCollection $filters): ?string
    {
        $condition = $filters->condition($this->invoice, new FilterTarget($this->qb, 'i'));
        if (null === $condition) {
            return null;
        }
        $sql = $this->qb->select('*')->from('tenant_invoice', 'i')->where($condition)->toSql();

        return substr($sql, (int) strpos($sql, ' WHERE ') + 7);
    }

    public function testConditionsAreJoinedByAnd(): void
    {
        $filters = new FilterCollection();
        self::assertNull($this->where($filters), 'no filter');

        $filters->add('a', self::filter('amount', 1));
        self::assertSame('"i"."amount" = :prism_1', $this->where($filters));

        $filters->add('none', self::filter('number', null));
        $filters->add('b', self::filter('number', 'F-1'));
        self::assertSame('("i"."amount" = :prism_1) AND ("i"."number" = :prism_2)', $this->where($filters), 'a filter without condition is skipped');
    }

    public function testDisableAndEnable(): void
    {
        $filters = new FilterCollection();
        $filters->add('a', self::filter('amount', 1));

        $filters->disable('a');
        self::assertFalse($filters->isEnabled('a'));
        self::assertTrue($filters->has('a'));
        self::assertNull($this->where($filters));

        $filters->enable('a');
        self::assertTrue($filters->isEnabled('a'));
        self::assertSame('"i"."amount" = :prism_1', $this->where($filters));
    }

    // ADR 0014 : le TenantFilter ne se désactive pas
    public function testLockedFilterCannotBeDisabled(): void
    {
        $filters = new FilterCollection();
        $filters->add('tenant', self::filter('amount', 1), locked: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "tenant" filter cannot be disabled; use TenantContext::runAsSystem() for an explicit cross-tenant operation (ADR 0014).');

        $filters->disable('tenant');
    }

    public function testUnknownAndDuplicateFilters(): void
    {
        $filters = new FilterCollection();
        try {
            $filters->disable('missing');
            self::fail('unknown filter');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Unknown filter "missing"; registered filters: none.', $e->getMessage());
        }

        $filters->add('a', self::filter('amount', 1));
        try {
            $filters->enable('b');
            self::fail('unknown filter');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Unknown filter "b"; registered filters: a.', $e->getMessage());
        }

        $this->expectExceptionMessage('A filter named "a" is already registered.');
        $filters->add('a', self::filter('amount', 2));
    }
}
