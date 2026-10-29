<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Tests\Support\TestDatabase;
use Prism\Orm\Audit\AuditReader;
use Prism\Orm\EntityManager;
use Prism\Orm\Tests\Functional\VectorFixtures\AuditedPassage;
use Prism\Orm\Vector\Vector;

/**
 * Un vecteur journalisé (RG-08.10) : le snapshot garde la valeur lue en base, binaire sous
 * MariaDB, mais le journal écrit l'ancienne valeur en texte, comme la nouvelle, sur toutes les
 * plateformes.
 */
final class VectorAuditTest extends MappingSchemaTestCase
{
    protected static function entities(): array
    {
        return [AuditedPassage::class];
    }

    protected function setUp(): void
    {
        if (null !== TestDatabase::url() && !TestDatabase::connection()->getPlatform()->supportsVectors()) {
            self::markTestSkipped('MySQL has no vector type (ADR 0017).');
        }
        parent::setUp();
    }

    public function testOldVectorIsJournaledAsText(): void
    {
        $em = new EntityManager($this->connection);
        $em->persist($passage = new AuditedPassage(new Vector([0.5, -1.0, 2.0])));
        $em->flush();
        $em->clear();

        $read = $em->find(AuditedPassage::class, $passage->id);
        self::assertNotNull($read);
        // lu, donc dans le snapshot ; un vecteur jamais lu est journalisé sans ancienne valeur (backlog)
        self::assertSame([0.5, -1.0, 2.0], $read->embedding->toArray());
        $read->embedding = new Vector([1.0, 0.0, 0.0]);
        $em->flush();
        $em->remove($read);
        $em->flush();

        $entries = new AuditReader($em)->history(AuditedPassage::class, $passage->id);
        self::assertSame(['insert', 'update', 'delete'], array_map(static fn ($e): string => $e->action, $entries));
        self::assertSame(['[0.5,-1.0,2.0]', '[1.0,0.0,0.0]'], $entries[1]->changes['embedding']);
        self::assertSame(['[1.0,0.0,0.0]', null], $entries[2]->changes['embedding']);
    }

    // un vecteur paresseux remplacé sans avoir été lu : son ancienne valeur est lue en base pour le journal
    public function testUnreadLazyVectorIsJournaledWithItsOldValue(): void
    {
        $em = new EntityManager($this->connection);
        $em->persist($passage = new AuditedPassage(new Vector([0.5, -1.0, 2.0])));
        $em->flush();
        $em->clear();

        $read = $em->find(AuditedPassage::class, $passage->id);
        self::assertNotNull($read);
        $read->embedding = new Vector([1.0, 0.0, 0.0]);
        $em->flush();

        $entries = new AuditReader($em)->history(AuditedPassage::class, $passage->id);
        self::assertSame(['[0.5,-1.0,2.0]', '[1.0,0.0,0.0]'], $entries[1]->changes['embedding']);
    }
}
