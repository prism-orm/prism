<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Id;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\MariaDbPlatform;
use Prism\Dbal\Platform\MySqlPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Dbal\Platform\SqlitePlatform;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Id\IdentityGenerator;
use Prism\Orm\Id\IdGeneratorFactory;
use Prism\Orm\Id\SequenceGenerator;
use Prism\Orm\Id\UuidV7Generator;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\FieldMapping;
use Prism\Orm\Metadata\IdMapping;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Article;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\AuditLine;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Country;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Document;

// RG-02.4 : même résolution que le SchemaTool
final class IdGeneratorFactoryTest extends TestCase
{
    /**
     * @return ClassMetadata<Article>
     */
    private static function metadata(?GenerationStrategy $strategy, string $type = 'integer', ?string $sequence = null): ClassMetadata
    {
        return new ClassMetadata(Article::class, 'article', new IdMapping('id', $strategy, $sequence), [new FieldMapping('id', 'id', $type)]);
    }

    public function testAutoIsSequenceOnPostgreSqlAndIdentityElsewhere(): void
    {
        $sequence = new IdGeneratorFactory(new PostgreSqlPlatform('16'))->getGenerator(self::metadata(GenerationStrategy::Auto));
        self::assertInstanceOf(SequenceGenerator::class, $sequence);
        self::assertSame('article_id_seq', $sequence->getSequence());
        self::assertFalse($sequence->isPostInsert());

        foreach ([new MySqlPlatform('8.4'), new MariaDbPlatform('11.8'), new SqlitePlatform('3.46')] as $platform) {
            $identity = new IdGeneratorFactory($platform)->getGenerator(self::metadata(GenerationStrategy::Auto));
            self::assertInstanceOf(IdentityGenerator::class, $identity, $platform->getName());
            self::assertTrue($identity->isPostInsert());
        }
    }

    public function testExplicitStrategies(): void
    {
        $factory = new IdGeneratorFactory(new MariaDbPlatform('11.8'));

        $sequence = $factory->getGenerator(new ClassMetadata(Document::class, 'document', new IdMapping('id', GenerationStrategy::Sequence, 'doc_seq'), [new FieldMapping('id', 'id', 'integer')]));
        self::assertInstanceOf(SequenceGenerator::class, $sequence);
        self::assertSame('doc_seq', $sequence->getSequence());

        $uuid = $factory->getGenerator(self::metadata(GenerationStrategy::UuidV7, 'uuid'));
        self::assertInstanceOf(UuidV7Generator::class, $uuid);
        self::assertFalse($uuid->isPostInsert());
        // un seul générateur UUID pour tout le processus : la monotonie vaut entre entités
        self::assertSame($uuid, $factory->getGenerator(new ClassMetadata(Country::class, 'country', new IdMapping('code', GenerationStrategy::UuidV7), [new FieldMapping('code', 'code', 'uuid')])));

        $assigned = new ClassMetadata(AuditLine::class, 'audit_line', new IdMapping('id'), [new FieldMapping('id', 'id', 'integer')]);
        self::assertNull($factory->getGenerator($assigned), 'assigned identifier');
    }

    public function testGeneratorIsCachedPerClass(): void
    {
        $factory = new IdGeneratorFactory(new PostgreSqlPlatform('16'));

        self::assertSame($factory->getGenerator(self::metadata(GenerationStrategy::Auto)), $factory->getGenerator(self::metadata(GenerationStrategy::Auto)));
    }

    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function platformsWithoutSequences(): iterable
    {
        yield 'MySQL' => [new MySqlPlatform('8.4')];
        yield 'SQLite' => [new SqlitePlatform('3.46')];
    }

    #[DataProvider('platformsWithoutSequences')]
    public function testSequenceIsRefusedWithoutSequences(AbstractPlatform $platform): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(\sprintf('GenerationStrategy::Sequence is not supported by %s; use Auto or Identity.', $platform->getName()));

        new IdGeneratorFactory($platform)->getGenerator(self::metadata(GenerationStrategy::Sequence));
    }
}
