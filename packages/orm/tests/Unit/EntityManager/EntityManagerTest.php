<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\EntityManager;

use PHPUnit\Framework\TestCase;
use Prism\Dbal\DriverManager;
use Prism\Orm\EntityManager;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Repository\EntityRepository;
use Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Document;

/**
 * Sans base : la connexion SQLite est paresseuse et n'est jamais ouverte ici.
 */
final class EntityManagerTest extends TestCase
{
    private static function em(): EntityManager
    {
        return new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'));
    }

    public function testDefaultRepositoryIsSharedPerClass(): void
    {
        $em = self::em();
        $repository = $em->getRepository(Document::class);

        self::assertSame(EntityRepository::class, $repository::class);
        self::assertSame($repository, $em->getRepository(Document::class));
        self::assertSame(Document::class, $repository->getClassName());
    }

    // ADR 0006
    public function testRepositoryClassMustExtendEntityRepository(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('its repository class ArrayObject must extend ' . EntityRepository::class . ' (ADR 0006).');

        self::em()->getRepository(WrongRepositoryEntity::class);
    }

    public function testExposesItsConnectionAndUnitOfWork(): void
    {
        $em = self::em();
        $document = new Document();
        $em->persist($document);

        self::assertTrue($em->contains($document));
        self::assertTrue($em->getUnitOfWork()->contains($document));
        self::assertSame('SQLite', $em->getConnection()->getPlatform()->getName());

        $em->detach($document);
        self::assertFalse($em->contains($document));
    }

    // RG-04.11 : taille de lot globale
    public function testBatchSizeMustBePositive(): void
    {
        $this->expectException(\Prism\Orm\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('The batch size must be at least 1, got 0.');

        new EntityManager(DriverManager::fromUrl('sqlite:///:memory:?serverVersion=3.46.0'), batchSize: 0);
    }
}
