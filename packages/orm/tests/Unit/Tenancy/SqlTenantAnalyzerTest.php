<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Orm\Tenancy\SqlTenantAnalyzer;

/**
 * L'analyse du SQL brut de la garde (RG-08.7, ADR 0014), au détail près : la liste exacte des
 * tables refusées et leur motif. tenant_invoice, tenant_client et « order » (mot réservé)
 * portent tenant_id, tenant_line company_id ; tenant_currency n'est pas tenantée.
 */
final class SqlTenantAnalyzerTest extends TestCase
{
    private const string U = SqlTenantAnalyzer::UNFILTERED;

    private const string P = SqlTenantAnalyzer::UNPARSABLE;

    private const string T = SqlTenantAnalyzer::TRUNCATE;

    /**
     * @return list<array{string, string}>
     */
    private static function violations(string $sql): array
    {
        return new SqlTenantAnalyzer(['tenant_invoice' => 'tenant_id', 'tenant_client' => 'tenant_id', 'tenant_line' => 'company_id', 'order' => 'tenant_id'])->violations($sql);
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function cases(): iterable
    {
        // lecture
        yield 'filtered' => ['SELECT id FROM tenant_invoice WHERE tenant_id = 1', []];
        yield 'not filtered' => ['SELECT id FROM tenant_invoice', [[self::U, 'tenant_invoice']]];
        yield 'keywords and names in any case' => ['select ID from TENANT_INVOICE where TENANT_ID = 1', []];
        yield 'mixed-case table, not filtered' => ['Select id From Tenant_Invoice', [[self::U, 'tenant_invoice']]];
        yield 'own tenant column' => ['SELECT id FROM tenant_line WHERE company_id = 1', []];
        yield 'another table s column' => ['SELECT id FROM tenant_line WHERE tenant_id = 1', [[self::U, 'tenant_line']]];
        yield 'two tables, both refused, in order' => ['SELECT 1 FROM tenant_invoice i, tenant_client c', [[self::U, 'tenant_invoice'], [self::U, 'tenant_client']]];
        yield 'two tables, the second refused' => ['SELECT 1 FROM tenant_invoice i, tenant_client c WHERE i.tenant_id = 1', [[self::U, 'tenant_client']]];
        yield 'two tables, both filtered' => ['SELECT 1 FROM tenant_invoice i, tenant_client c WHERE i.tenant_id = 1 AND c.tenant_id = 1', []];
        yield 'same table twice, reported once' => ['SELECT 1 FROM tenant_invoice a JOIN tenant_invoice b ON b.id = a.id', [[self::U, 'tenant_invoice']]];
        yield 'table name as qualifier without alias' => ['SELECT 1 FROM tenant_invoice JOIN tenant_currency c ON c.id = 1 WHERE tenant_invoice.tenant_id = 1', []];
        yield 'table name as qualifier despite an alias' => ['SELECT 1 FROM tenant_invoice i JOIN tenant_currency c ON c.id = 1 WHERE tenant_invoice.tenant_id = 1', [[self::U, 'tenant_invoice']]];
        yield 'AS alias' => ['SELECT 1 FROM tenant_invoice AS i WHERE i.tenant_id = 1', []];
        yield 'quoted alias' => ['SELECT 1 FROM "tenant_invoice" "Inv" WHERE "Inv"."tenant_id" = 1', []];
        yield 'escaped quote in an identifier' => ['SELECT "a""b" FROM tenant_invoice WHERE tenant_id = 1', []];
        yield 'escaped backtick in an identifier' => ['SELECT `a``b` FROM tenant_invoice WHERE tenant_id = 1', []];
        yield 'quoted table name' => ['SELECT 1 FROM "tenant_invoice"', [[self::U, 'tenant_invoice']]];
        yield 'quotes are not part of the name' => ['SELECT 1 FROM `tenant_invoice` WHERE `tenant_id` = 1', []];
        yield 'schema-qualified' => ['SELECT 1 FROM app.tenant_invoice WHERE tenant_id = 1', []];
        yield 'schema-qualified column' => ['SELECT 1 FROM tenant_invoice i JOIN tenant_currency c ON c.id = 1 WHERE app.i.tenant_id = 1', []];
        yield 'cast in the condition' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = :t::int', []];
        yield 'function with the column' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id(1) = 1', [[self::U, 'tenant_invoice']]];
        yield 'column as a qualifier' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id.x = 1', [[self::U, 'tenant_invoice']]];
        yield 'nested parentheses' => ['SELECT 1 FROM tenant_invoice i WHERE ((i.tenant_id = 1)) AND (i.id > 0)', []];
        yield 'OR of filtered branches' => ['SELECT 1 FROM tenant_invoice i WHERE (i.tenant_id = 1 OR i.tenant_id IS NULL) AND i.id > 0', []];
        yield 'OR with an unfiltered branch' => ['SELECT 1 FROM tenant_invoice i WHERE (i.tenant_id = 1 OR i.id > 0) AND i.id < 9', [[self::U, 'tenant_invoice']]];
        yield 'OR at the top' => ['SELECT 1 FROM tenant_invoice i WHERE i.tenant_id = 1 OR i.id = 2', [[self::U, 'tenant_invoice']]];
        yield 'empty parentheses' => ['SELECT 1 FROM tenant_invoice i WHERE () AND i.id = 1', [[self::U, 'tenant_invoice']]];
        yield 'BETWEEN then the tenant' => ['SELECT 1 FROM tenant_invoice i WHERE i.id BETWEEN 1 AND 5 AND i.tenant_id = 1', []];
        yield 'BETWEEN alone' => ['SELECT 1 FROM tenant_invoice i WHERE i.tenant_id BETWEEN 1 AND 5', []];
        yield 'BETWEEN on another column, then OR' => ['SELECT 1 FROM tenant_invoice i WHERE i.id BETWEEN 1 AND 5 OR i.tenant_id = 1', [[self::U, 'tenant_invoice']]];
        yield 'condition after GROUP BY is not a WHERE' => ['SELECT 1 FROM tenant_invoice i GROUP BY i.tenant_id', [[self::U, 'tenant_invoice']]];
        yield 'WHERE in a function before the clause' => ['SELECT COUNT(*) FROM tenant_invoice i WHERE i.tenant_id IN (1, 2) ORDER BY i.id LIMIT 3', []];
        yield 'HAVING ends the WHERE' => ['SELECT 1 FROM tenant_invoice i WHERE i.id = 1 HAVING i.tenant_id = 1', [[self::U, 'tenant_invoice']]];
        yield 'semicolon ends the WHERE' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = 1;', []];

        // jointures
        yield 'join filtered in its ON' => ['SELECT 1 FROM tenant_invoice i INNER JOIN tenant_client c ON c.id = i.client_id AND c.tenant_id = 1 WHERE i.tenant_id = 1', []];
        yield 'LEFT OUTER JOIN' => ['SELECT 1 FROM tenant_invoice i LEFT OUTER JOIN tenant_client c ON c.id = i.client_id WHERE i.tenant_id = 1', [[self::U, 'tenant_client']]];
        yield 'RIGHT, FULL, CROSS, NATURAL joins' => ['SELECT 1 FROM tenant_currency a RIGHT JOIN tenant_currency b ON 1 = 1 FULL JOIN tenant_currency c ON 1 = 1 CROSS JOIN tenant_currency d NATURAL JOIN tenant_invoice e', [[self::U, 'tenant_invoice']]];
        yield 'one ON for each join' => ['SELECT 1 FROM tenant_invoice i JOIN tenant_client c ON c.tenant_id = 1 JOIN tenant_line l ON l.company_id = 1 WHERE i.tenant_id = 1', []];
        yield 'ON of another join does not count' => ['SELECT 1 FROM tenant_invoice i JOIN tenant_client c ON c.id = 1 JOIN tenant_line l ON c.tenant_id = 1 AND l.company_id = 1 WHERE i.tenant_id = 1', [[self::U, 'tenant_client']]];
        yield 'ON with a function in parentheses' => ['SELECT 1 FROM tenant_currency x JOIN tenant_client c ON (c.tenant_id = 1) AND LEFT(c.name, 1) = 1 JOIN tenant_invoice i ON i.tenant_id = 1', []];
        yield 'JOIN … USING' => ['SELECT 1 FROM tenant_invoice i JOIN tenant_client c USING (id) WHERE i.tenant_id = 1 AND c.tenant_id = 1', []];
        yield 'derived table, outer filtered' => ['SELECT 1 FROM (SELECT 1) AS d JOIN tenant_invoice i ON i.tenant_id = 1', []];
        yield 'derived table without alias' => ['SELECT 1 FROM (SELECT 1) JOIN tenant_invoice i ON i.id = 1', [[self::U, 'tenant_invoice']]];
        yield 'STRAIGHT_JOIN' => ['SELECT 1 FROM tenant_currency c STRAIGHT_JOIN tenant_invoice i ON i.id = 1', [[self::U, 'tenant_invoice']]];

        // sous-requêtes, formes refusées en bloc
        yield 'subquery on a tenanted table' => ['SELECT 1 FROM tenant_currency WHERE id IN (SELECT 1 FROM tenant_invoice WHERE tenant_id = 1)', [[self::P, 'tenant_invoice']]];
        yield 'two subqueries, same table, reported once' => ['SELECT (SELECT 1 FROM tenant_invoice), (SELECT 2 FROM tenant_invoice) FROM tenant_currency', [[self::P, 'tenant_invoice']]];
        yield 'subquery, then the outer table' => ['SELECT (SELECT 1 FROM tenant_currency) AS a, id FROM tenant_invoice', [[self::U, 'tenant_invoice']]];
        yield 'subquery and unfiltered outer table' => ['SELECT (SELECT 1 FROM tenant_client) AS a FROM tenant_invoice', [[self::P, 'tenant_client'], [self::U, 'tenant_invoice']]];
        yield 'subquery with WITH' => ['SELECT 1 FROM tenant_currency WHERE id IN (WITH x AS (SELECT 1) SELECT id FROM tenant_client)', [[self::P, 'tenant_client']]];
        yield 'unclosed subquery' => ['SELECT 1 FROM tenant_currency WHERE id IN (SELECT id FROM tenant_client', [[self::P, 'tenant_client']]];
        yield 'WITH' => ['WITH x AS (SELECT 1 FROM tenant_client) SELECT 1 FROM tenant_invoice', [[self::P, 'tenant_client'], [self::P, 'tenant_invoice']]];
        yield 'MERGE' => ['MERGE INTO tenant_invoice USING tenant_currency ON 1 = 1', [[self::P, 'tenant_invoice']]];
        yield 'UNION' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = 1 UNION SELECT 1 FROM tenant_currency', [[self::P, 'tenant_invoice']]];
        yield 'INTERSECT' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = 1 INTERSECT SELECT 1 FROM tenant_invoice WHERE tenant_id = 1', [[self::P, 'tenant_invoice']]];
        yield 'EXCEPT' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = 1 EXCEPT SELECT 1 FROM tenant_invoice WHERE tenant_id = 1', [[self::P, 'tenant_invoice']]];
        yield 'several statements' => ['SELECT 1; SELECT 1 FROM tenant_client WHERE tenant_id = 1', [[self::P, 'tenant_client']]];
        yield 'one statement and a semicolon' => ['SELECT 1 FROM tenant_client WHERE tenant_id = 1 ;', []];
        yield 'starts with a parenthesis' => ['(SELECT 1 FROM tenant_client WHERE tenant_id = 1)', [[self::P, 'tenant_client']]];
        yield 'starts with a quoted identifier' => ['"tenant_client"', [[self::P, 'tenant_client']]];
        yield 'parenthesized join' => ['SELECT 1 FROM tenant_currency c JOIN (tenant_invoice i JOIN tenant_client d ON 1 = 1) ON 1 = 1', [[self::P, 'tenant_invoice'], [self::P, 'tenant_client']]];
        yield 'keyword where a table is expected' => ['SELECT 1 FROM WHERE tenant_invoice', [[self::P, 'tenant_invoice']]];
        // filet de sécurité : une table tenantée nommée hors des tables analysées
        yield 'tenanted name outside the analyzed tables' => ['SELECT tenant_invoice.id FROM tenant_currency', [[self::P, 'tenant_invoice']]];
        // RG-01.3 : une table au nom réservé, citée ; le mot-clé ORDER n'est pas elle
        yield 'ORDER BY is not the order table' => ['SELECT name FROM information_schema.TABLES ORDER BY name', []];
        yield 'quoted reserved table, filtered' => ['SELECT 1 FROM "order" o WHERE o.tenant_id = 1 ORDER BY o.id', []];
        yield 'quoted reserved table, not filtered' => ['SELECT 1 FROM `order` ORDER BY id', [[self::U, 'order']]];
        yield 'JOIN then a parenthesis' => ['SELECT 1 FROM tenant_currency c JOIN (tenant_client d) ON d.tenant_id = 1', [[self::P, 'tenant_client']]];

        // découpage incertain : toutes les tables nommées, dans l'ordre du texte
        yield 'backslash in a string' => ["SELECT 1 FROM tenant_client c, tenant_invoice i WHERE c.x = 'a\\'", [[self::P, 'tenant_client'], [self::P, 'tenant_invoice']]];
        yield 'order of the text, not of the entities' => ["SELECT 1 FROM tenant_invoice i, tenant_client c WHERE c.x = 'a\\'", [[self::P, 'tenant_invoice'], [self::P, 'tenant_client']]];
        yield 'backslash in an identifier' => ['SELECT "a\\b" FROM tenant_invoice WHERE tenant_id = 1', [[self::P, 'tenant_invoice']]];
        yield 'MySQL executable comment' => ['SELECT 1 /*! FROM tenant_client */ FROM tenant_currency', [[self::P, 'tenant_client']]];
        yield 'block comment is ignored' => ['SELECT 1 /* tenant_client */ FROM tenant_currency', []];
        yield '-- comment is ignored' => ["SELECT 1 FROM tenant_currency -- tenant_client\n WHERE 1 = 1", []];
        yield '--x' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = 1 --x', [[self::P, 'tenant_invoice']]];
        yield 'operator with /*' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = 1 +/* x', [[self::P, 'tenant_invoice']]];
        yield 'operator with */' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = 1 */', [[self::P, 'tenant_invoice']]];
        yield '#' => ['SELECT 1 FROM tenant_invoice WHERE tenant_id = 1 # x', [[self::P, 'tenant_invoice']]];
        yield 'unclosed quote' => ["SELECT 1 FROM tenant_invoice WHERE x = 'a", [[self::P, 'tenant_invoice']]];
        yield 'no tenanted table, uncertain' => ["SELECT 1 FROM tenant_currency WHERE x = 'a\\'", []];

        // statements et formes qui passent
        yield 'DDL' => ['ALTER TABLE tenant_invoice ADD x INT', []];
        yield 'lowercase DDL' => ['drop table tenant_invoice', []];
        yield 'EXPLAIN' => ['EXPLAIN SELECT 1 FROM tenant_invoice', []];
        yield 'no tenanted table' => ['SELECT 1 FROM tenant_currency', []];
        yield 'string literal named like a table' => ["SELECT 1 FROM tenant_currency WHERE code = 'tenant_invoice'", []];
        yield 'empty query' => ['', []];

        // écritures
        yield 'TRUNCATE' => ['TRUNCATE TABLE tenant_invoice', [[self::T, 'tenant_invoice']]];
        yield 'truncate in lower case' => ['truncate tenant_client', [[self::T, 'tenant_client']]];
        yield 'UPDATE filtered' => ['UPDATE tenant_invoice SET x = 1 WHERE tenant_id = 1', []];
        yield 'UPDATE not filtered' => ['UPDATE tenant_invoice SET x = 1 WHERE id = 1', [[self::U, 'tenant_invoice']]];
        yield 'UPDATE with modifiers' => ['UPDATE LOW_PRIORITY IGNORE tenant_invoice SET x = 1 WHERE tenant_id = 1', []];
        yield 'UPDATE ONLY' => ['UPDATE ONLY tenant_invoice SET x = 1', [[self::U, 'tenant_invoice']]];
        yield 'UPDATE OR ROLLBACK (SQLite)' => ['UPDATE OR ROLLBACK tenant_invoice SET x = 1 WHERE tenant_id = 1', []];
        yield 'multi-table UPDATE' => ['UPDATE tenant_invoice i JOIN tenant_client c ON c.id = i.client_id SET i.x = 1 WHERE i.tenant_id = 1', [[self::U, 'tenant_client']]];
        yield 'UPDATE … FROM' => ['UPDATE tenant_invoice i SET x = 1 FROM tenant_client c WHERE i.tenant_id = 1', [[self::U, 'tenant_client']]];
        yield 'UPDATE … FROM a parenthesized join' => ['UPDATE tenant_invoice i SET x = 1 FROM (tenant_client c JOIN tenant_line l ON 1 = 1) WHERE i.tenant_id = 1', [[self::P, 'tenant_invoice'], [self::P, 'tenant_client'], [self::P, 'tenant_line']]];
        yield 'DELETE filtered' => ['DELETE FROM tenant_invoice WHERE tenant_id = 1', []];
        yield 'DELETE not filtered' => ['DELETE FROM tenant_invoice', [[self::U, 'tenant_invoice']]];
        yield 'DELETE … USING' => ['DELETE FROM tenant_invoice i USING tenant_client c WHERE i.tenant_id = 1', [[self::U, 'tenant_client']]];
        yield 'DELETE … USING a parenthesized join' => ['DELETE FROM tenant_invoice i USING (tenant_client c JOIN tenant_line l ON 1 = 1) WHERE i.tenant_id = 1', [[self::P, 'tenant_invoice'], [self::P, 'tenant_client'], [self::P, 'tenant_line']]];
        yield 'multi-table DELETE' => ['DELETE i FROM tenant_invoice i WHERE i.tenant_id = 1', [[self::P, 'tenant_invoice']]];
        yield 'DELETE alone' => ['DELETE', []];
        yield 'INSERT with the column' => ['INSERT INTO tenant_invoice (number, tenant_id) VALUES (?, ?)', []];
        yield 'INSERT, column in another case' => ['INSERT INTO tenant_invoice (number, "TENANT_ID") VALUES (?, ?)', []];
        yield 'INSERT without the column' => ['INSERT INTO tenant_invoice (number) VALUES (?)', [[self::U, 'tenant_invoice']]];
        yield 'INSERT, column only in VALUES' => ['INSERT INTO tenant_invoice (number) VALUES (tenant_id)', [[self::U, 'tenant_invoice']]];
        yield 'INSERT of own column' => ['INSERT INTO tenant_line (label, company_id) VALUES (?, ?)', []];
        yield 'INSERT VALUES' => ['INSERT INTO tenant_invoice VALUES (1, 2)', []];
        yield 'INSERT VALUE' => ['INSERT INTO tenant_invoice VALUE (1, 2)', []];
        yield 'INSERT DEFAULT VALUES' => ['INSERT INTO tenant_invoice DEFAULT VALUES', [[self::U, 'tenant_invoice']]];
        yield 'INSERT … SET' => ['INSERT INTO tenant_invoice SET tenant_id = 1', []];
        yield 'INSERT … SET without the column' => ['INSERT INTO tenant_invoice SET number = 1', [[self::U, 'tenant_invoice']]];
        yield 'INSERT IGNORE' => ['INSERT IGNORE INTO tenant_invoice (tenant_id) VALUES (1)', []];
        yield 'INSERT OR REPLACE (SQLite)' => ['INSERT OR REPLACE INTO tenant_invoice (number) VALUES (1)', [[self::U, 'tenant_invoice']]];
        yield 'REPLACE' => ['REPLACE INTO tenant_invoice (number) VALUES (1)', [[self::U, 'tenant_invoice']]];
        yield 'INSERT with an alias (PostgreSQL)' => ['INSERT INTO tenant_invoice AS i (number, tenant_id) VALUES (1, 2)', []];
        yield 'INSERT without INTO' => ['INSERT tenant_invoice (number) VALUES (1)', [[self::U, 'tenant_invoice']]];
        yield 'INSERT into a table without tenant' => ['INSERT INTO tenant_currency (code) VALUES (1)', []];
        yield 'INSERT … SELECT' => ['INSERT INTO tenant_invoice (tenant_id) SELECT 1 FROM tenant_currency', [[self::P, 'tenant_invoice']]];
        yield 'INSERT … SELECT from a tenanted table' => ['INSERT INTO tenant_currency (code) SELECT 1 FROM tenant_client WHERE tenant_id = 1', [[self::P, 'tenant_client']]];
        yield 'INSERT … SELECT, same table twice' => ['INSERT INTO tenant_invoice (tenant_id) SELECT tenant_id FROM tenant_invoice', [[self::P, 'tenant_invoice']]];
        yield 'INSERT without a table' => ['INSERT INTO (tenant_client)', [[self::P, 'tenant_client']]];
        // mal formée : la liste non fermée n'a pas de colonne du tenant reconnue
        yield 'INSERT, unclosed column list' => ['INSERT INTO tenant_invoice (number, tenant_id', [[self::U, 'tenant_invoice']]];
    }

    /**
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('cases')]
    public function testViolations(string $sql, array $expected): void
    {
        self::assertSame($expected, self::violations($sql));
    }
}
