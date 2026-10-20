<?php

declare(strict_types=1);

namespace Prism\PHPStan\Tests\Unit;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Prism\Dbal\Query\Order;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Batch\Customer;
use Prism\PHPStan\Rule\FindByRule;
use Prism\PHPStan\Tests\Fixtures\Client;
use Prism\PHPStan\Tests\Fixtures\Invoice;

/**
 * RG-05.8 : clés de critères et de tri de findBy() et des méthodes voisines.
 *
 * @extends RuleTestCase<FindByRule>
 */
final class FindByRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(FindByRule::class);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [\dirname(__DIR__, 2) . '/extension.neon'];
    }

    public function testCriteriaAndOrder(): void
    {
        $invoice = Invoice::class;
        $client = Client::class;
        $relation = ': findBy() compares only fields and owning ToOne relations; join it with the typed query builder instead.';

        $this->analyse([\dirname(__DIR__) . '/data/find-by.php'], [
            ["Unknown field {$invoice}::\$refrence in the criteria of findBy(). Did you mean \"reference\"?", 21],
            ["Unknown field {$invoice}::\$billing.town in the criteria of findBy().", 22],
            ["Unknown field {$invoice}::\$amount in the criteria of findBy().", 23],
            ["Unknown field {$invoice}::\$paidat in the criteria of findOneBy(). Did you mean \"paidAt\"?", 24],
            ["Unknown field {$invoice}::\$statut in the criteria of count(). Did you mean \"status\"?", 25],
            ["Unknown field {$invoice}::\$clients in the criteria of iterate(). Did you mean \"client\"?", 26],
            ["{$client}::\$invoices is a collection" . $relation, 34],
            ["{$client}::\$contract is the inverse side of a relation" . $relation, 35],
            ["Unknown field {$client}::\$nickname in the criteria of findBy().", 36],
            ["Unknown field {$invoice}::\$totl in the order of findBy(). Did you mean \"total\"?", 44],
            ["The order of {$invoice}::\$total in findBy() is string, not a " . Order::class . ' case.', 45],
            ["Unknown field {$invoice}::\$totl in the criteria of findBy(). Did you mean \"total\"?", 54],
            ['Unknown field ' . Customer::class . '::$nme in the criteria of findBy(). Did you mean "name"?', 57],
        ]);
    }
}
