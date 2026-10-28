<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Tests\Support\InMemoryCachePool;
use Prism\Orm\Tests\Support\SpyDriver;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Address;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Client;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Invoice;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\InvoiceLine;
use Prism\Orm\Tests\Unit\Mapping\Fixtures\Tag;

final class MetadataFactoryTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Mapping/Fixtures';

    private static function spy(): SpyDriver
    {
        return new SpyDriver(new AttributeDriver([self::FIXTURES]));
    }

    private static function factory(SpyDriver $driver, ?InMemoryCachePool $cache = null): MetadataFactory
    {
        return new MetadataFactory($driver, $cache);
    }

    // critère de sortie : zéro lecture d'attributs après échauffement
    public function testNoAttributeIsReadAfterWarmUp(): void
    {
        $cache = new InMemoryCachePool();
        self::factory(self::spy(), $cache)->warmUp();

        $driver = self::spy();
        $factory = self::factory($driver, $cache);
        $all = $factory->getAllMetadata();
        $factory->getMetadataFor(Invoice::class);
        $factory->isEntity(Client::class);

        self::assertSame([Client::class, Invoice::class, InvoiceLine::class, Tag::class], array_map(static fn (ClassMetadata $m): string => $m->className, $all));
        self::assertSame(0, $driver->calls, 'The driver must not be called once the cache is warm.');
    }

    // les metadata passent réellement par serialize() / unserialize()
    public function testCachedMetadataEqualsDriverMetadata(): void
    {
        $cache = new InMemoryCachePool();
        self::factory(self::spy(), $cache)->warmUp();

        $fromCache = self::factory(self::spy(), $cache)->getMetadataFor(Invoice::class);
        $fromDriver = new AttributeDriver()->loadMetadata(Invoice::class);

        self::assertEquals($fromDriver, $fromCache);
        self::assertNotSame($fromDriver, $fromCache);
    }

    public function testWithoutCacheEveryFactoryReadsAttributes(): void
    {
        $driver = self::spy();

        self::factory($driver)->getMetadataFor(Invoice::class);
        self::factory($driver)->getMetadataFor(Invoice::class);

        self::assertSame(2, $driver->calls);
    }

    public function testSameClassReturnsTheSameInstance(): void
    {
        $driver = self::spy();
        $factory = self::factory($driver);

        self::assertSame($factory->getMetadataFor(Tag::class), $factory->getMetadataFor(Tag::class));
        self::assertSame(1, $driver->calls);
    }

    public function testLazyLoadingFillsTheCache(): void
    {
        $cache = new InMemoryCachePool();
        self::factory(self::spy(), $cache)->getMetadataFor(Tag::class);

        $driver = self::spy();
        self::factory($driver, $cache)->getMetadataFor(Tag::class);

        self::assertSame(0, $driver->calls);
        self::assertSame(['prism.metadata.Prism.Orm.Tests.Unit.Mapping.Fixtures.Tag'], $cache->keys(), 'PSR-6 forbids backslashes in keys.');
    }

    public function testWarmUpRefusesAnInvalidMappingAndWritesNothing(): void
    {
        $cache = new InMemoryCachePool();
        // le type de UnknownTypeEntity n'est pas enregistré
        $factory = new MetadataFactory(new SpyDriver(new AttributeDriver([__DIR__ . '/../Mapping/InvalidFixtures'])), $cache);

        try {
            $factory->warmUp();
            self::fail('Expected MappingException.');
        } catch (MappingException $e) {
            self::assertStringContainsString('unknown type "money"', $e->getMessage());
        }

        self::assertSame(0, $cache->writes);
    }

    public function testCorruptedCacheEntryIsReloadedFromTheDriver(): void
    {
        $cache = new InMemoryCachePool();
        self::factory(self::spy(), $cache)->warmUp();
        $cache->corrupt('prism.metadata.Prism.Orm.Tests.Unit.Mapping.Fixtures.Invoice', 'O:22:"Prism\Removed\Metadata":0:{}');
        $cache->corrupt('prism.metadata.__entities', serialize(['App\Removed\Entity']));

        $driver = self::spy();
        $factory = self::factory($driver, $cache);

        self::assertSame(Invoice::class, $factory->getMetadataFor(Invoice::class)->className);
        self::assertCount(4, $factory->getAllClassNames());
        self::assertSame(2, $driver->calls);
    }

    public function testClearForgetsOnlyMetadataEntries(): void
    {
        $cache = new InMemoryCachePool();
        $cache->save($cache->getItem('app.other')->set('kept'));
        $factory = self::factory(self::spy(), $cache);
        $factory->warmUp();

        $factory->clear();

        self::assertSame(['app.other'], $cache->keys());
        $driver = self::spy();
        self::factory($driver, $cache)->getMetadataFor(Tag::class);
        self::assertSame(1, $driver->calls);
    }

    public function testIsEntity(): void
    {
        $factory = self::factory(self::spy());

        self::assertTrue($factory->isEntity(Invoice::class));
        self::assertFalse($factory->isEntity(Address::class));
    }
}
