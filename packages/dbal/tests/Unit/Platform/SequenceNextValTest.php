<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\Exception\InvalidSchemaException;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;

final class SequenceNextValTest extends TestCase
{
    public function testPostgreSqlQuotesTheNameThenPassesItAsText(): void
    {
        self::assertSame("SELECT nextval('\"invoice_id_seq\"')", new PostgreSqlPlatform('16')->getSequenceNextValSql('invoice_id_seq'));
        self::assertSame("SELECT nextval('\"O''Seq\"')", new PostgreSqlPlatform('16')->getSequenceNextValSql("O'Seq"));
    }

    public function testMariaDb(): void
    {
        self::assertSame('SELECT NEXTVAL(`invoice_id_seq`)', new MariaDbPlatform('11.8')->getSequenceNextValSql('invoice_id_seq'));
    }

    public function testPlatformsWithoutSequencesRefuse(): void
    {
        foreach ([new MySqlPlatform('8.4'), new SqlitePlatform('3.46')] as $platform) {
            try {
                $platform->getSequenceNextValSql('s');
                self::fail('Expected InvalidSchemaException.');
            } catch (InvalidSchemaException $e) {
                self::assertSame(\sprintf('%s does not support sequences.', $platform->getName()), $e->getMessage());
            }
        }
    }
}
