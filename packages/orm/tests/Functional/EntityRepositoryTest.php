<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Functional;

use Prism\Dbal\Query\Order;
use Prism\Orm\EntityManager;
use Prism\Orm\EntityManagerInterface;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Repository\EntityRepository;
use Prism\Orm\Tests\Functional\PersisterFixtures\Customer;
use Prism\Orm\Tests\Functional\PersisterFixtures\CustomerRepository;
use Prism\Orm\Tests\Functional\PersisterFixtures\Label;
use Prism\Orm\UnitOfWork\EntityState;

final class EntityRepositoryTest extends MappingSchemaTestCase
{
    private EntityManager $em;

    protected static function entities(): array
    {
        return [Customer::class, Label::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = new EntityManager($this->connection);
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            foreach ([['Dupont', 'Paris'], ['Martin', 'Lyon'], ['Bernard', 'Paris'], ['Durand', 'Nantes'], ['Petit', 'Paris']] as [$name, $city]) {
                $em->persist(new Customer($name, $city));
            }
        });
        $this->em->clear();
    }

    /**
     * @param list<Customer> $customers
     *
     * @return list<string>
     */
    private static function names(array $customers): array
    {
        return array_map(static fn (Customer $c): string => $c->name, $customers);
    }

    public function testFindByCriteriaOrderLimitAndOffset(): void
    {
        $repository = $this->em->getRepository(Customer::class);

        self::assertSame(['Bernard', 'Dupont', 'Petit'], self::names($repository->findBy(['city' => 'Paris'], ['name' => Order::Asc])));
        self::assertSame(['Dupont'], self::names($repository->findBy(['city' => 'Paris'], ['name' => Order::Asc], limit: 1, offset: 1)));
        self::assertSame(['Martin', 'Durand'], self::names($repository->findBy(['city' => ['Lyon', 'Nantes']], ['name' => Order::Desc])));
        self::assertCount(5, $repository->findAll());
        self::assertSame('Petit', $repository->findOneBy(['city' => 'Paris'], ['name' => Order::Desc])?->name);
        self::assertNull($repository->findOneBy(['city' => 'Bordeaux']));
    }

    public function testCount(): void
    {
        $repository = $this->em->getRepository(Customer::class);

        self::assertSame(5, $repository->count());
        self::assertSame(3, $repository->count(['city' => 'Paris']));
        self::assertSame(0, $repository->count(['city' => 'Bordeaux']));
    }

    public function testFoundEntitiesGoThroughTheIdentityMap(): void
    {
        $repository = $this->em->getRepository(Customer::class);
        $dupont = $repository->findOneBy(['name' => 'Dupont']);
        self::assertNotNull($dupont);

        self::assertSame($dupont, $repository->find($dupont->id));
        self::assertContains($dupont, $repository->findBy(['city' => 'Paris']));
    }

    // ADR 0006, RG-07.2
    public function testCustomRepositoryClass(): void
    {
        $repository = $this->em->getRepository(Customer::class);

        // l'extension PHPStan le déduit déjà de #[Entity(repositoryClass:)] ; vérifié ici à l'exécution
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(CustomerRepository::class, $repository);
        self::assertSame($repository, $this->em->getRepository(Customer::class));
        self::assertSame(['Bernard', 'Dupont', 'Petit'], self::names($repository->inCity('Paris')));
        self::assertSame(Customer::class, $repository->getClassName());
        self::assertSame(EntityRepository::class, $this->em->getRepository(Label::class)::class);
    }

    // ADR 0006 : l'EntityManager et la metadata sont accessibles au repository métier
    public function testCustomRepositoryReachesItsEntityManagerAndMetadata(): void
    {
        $repository = $this->em->getRepository(Customer::class);
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(CustomerRepository::class, $repository);
        $customer = $repository->inCity('Paris')[0];

        $repository->moveTo($customer, 'Lyon');
        $this->em->clear();

        self::assertSame(['Bernard', 'Martin'], self::names($repository->inCity('Lyon')), 'flushed through getEntityManager()');
        self::assertSame('customer', $repository->tableName());
    }

    public function testUnknownFieldIsRefused(): void
    {
        $this->expectException(MappingException::class);

        // @phpstan-ignore prism.findBy.unknownField (refusé aussi à l'exécution)
        $this->em->getRepository(Customer::class)->findBy(['country' => 'FR']);
    }

    // RG-03.10, décision F : pagination sur clé, chaque lot détaché
    public function testIterateReadsEveryRowByBatchesAndDetachesThem(): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em): void {
            for ($i = 1; $i <= 250; ++$i) {
                $em->persist(new Label(\sprintf('L%03d', $i)));
            }
        });
        $this->em->clear();

        $codes = [];
        $first = null;
        foreach ($this->em->getRepository(Label::class)->iterate(batchSize: 100) as $label) {
            $codes[] = $label->code;
            $first ??= $label;
        }

        self::assertCount(250, $codes);
        self::assertSame(\sprintf('L%03d', 1), $codes[0]);
        self::assertSame(\sprintf('L%03d', 250), $codes[249], 'ordered by id');
        self::assertSame(EntityState::Detached, $this->em->getUnitOfWork()->getEntityState($first));

        // critères et lot plus petit que le résultat
        $paris = iterator_to_array($this->em->getRepository(Customer::class)->iterate(['city' => 'Paris'], 2), false);
        self::assertSame(['Bernard', 'Dupont', 'Petit'], self::sorted($paris));
    }

    /**
     * @param list<Customer> $customers
     *
     * @return list<string>
     */
    private static function sorted(array $customers): array
    {
        $names = self::names($customers);
        sort($names);

        return $names;
    }
}
