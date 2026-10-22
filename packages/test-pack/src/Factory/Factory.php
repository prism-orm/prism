<?php

declare(strict_types=1);

namespace Prism\TestPack\Factory;

use Prism\Orm\EntityManagerInterface;
use Prism\TestPack\Exception\TestPackException;
use Prism\TestPack\TestContext;

/**
 * Crée des entités pour les tests : on décrit une fois une entité « normale » (defaults()), chaque
 * test ne précise que ce qui compte pour lui. Seule classe du test-pack ouverte à l'héritage.
 *
 *     final class OrderFactory extends Factory
 *     {
 *         protected static function class(): string { return Order::class; }
 *         protected function defaults(): array
 *         {
 *             return ['reference' => fn (int $n) => 'R' . $n, 'customer' => CustomerFactory::new()];
 *         }
 *     }
 *
 *     OrderFactory::new()->many(10);                    // persistées, un seul flush
 *     OrderFactory::new(['total' => '0.00'])->create();
 *     OrderFactory::new()->make();                      // construite, non persistée
 *
 * Valeurs : une closure reçoit le numéro de séquence (1, 2, 3… par classe de factory) ; une
 * factory est construite à la demande, et persistée avec l'entité par create() et many().
 * Construction : le constructeur reçoit les valeurs qui portent le nom de ses paramètres, les
 * autres sont écrites par Reflection (une propriété readonly déjà écrite par le constructeur
 * ne peut pas l'être).
 *
 * @template T of object
 *
 * @api
 *
 * @extensible
 */
abstract class Factory
{
    /** @var array<class-string, int> */
    private static array $sequences = [];

    /**
     * @param array<string, mixed> $attributes
     */
    final public function __construct(
        private array $attributes = [],
        private ?string $entityManager = null,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    final public static function new(array $attributes = []): static
    {
        // new static() se lirait Factory<object> : le type T vient de la sous-classe
        return new \ReflectionClass(static::class)->newInstance($attributes);
    }

    /**
     * @return class-string<T>
     */
    abstract protected static function class(): string;

    /**
     * Les valeurs d'une entité « normale ».
     *
     * @return array<string, mixed>
     */
    abstract protected function defaults(): array;

    /**
     * @param array<string, mixed> $attributes
     */
    final public function with(array $attributes): static
    {
        $factory = clone $this;
        $factory->attributes = [...$this->attributes, ...$attributes];

        return $factory;
    }

    // un autre EntityManager que celui qui mappe la classe
    final public function using(string $entityManager): static
    {
        $factory = clone $this;
        $factory->entityManager = $entityManager;

        return $factory;
    }

    /**
     * Construite, jamais persistée ; ses factories aussi.
     *
     * @return T
     */
    final public function make(): object
    {
        return $this->build(null);
    }

    /**
     * Construite, persistée avec ses factories, puis flushée.
     *
     * @return T
     */
    final public function create(): object
    {
        $em = $this->entityManager();
        $entity = $this->build($em);
        $em->flush();

        return $entity;
    }

    /**
     * $count entités persistées, un seul flush.
     *
     * @return list<T>
     */
    final public function many(int $count): array
    {
        if ($count < 1) {
            throw new TestPackException(\sprintf('%s::many() needs at least one entity, got %d.', static::class, $count));
        }
        $em = $this->entityManager();
        $entities = [];
        for ($i = 0; $i < $count; ++$i) {
            $entities[] = $this->build($em);
        }
        $em->flush();

        return $entities;
    }

    private function entityManager(): EntityManagerInterface
    {
        return null === $this->entityManager ? TestContext::entityManagerFor(static::class()) : TestContext::entityManager($this->entityManager);
    }

    /**
     * @return T
     */
    private function build(?EntityManagerInterface $em): object
    {
        $sequence = self::$sequences[static::class] = (self::$sequences[static::class] ?? 0) + 1;
        $values = [];
        foreach ([...$this->defaults(), ...$this->attributes] as $name => $value) {
            $values[$name] = match (true) {
                $value instanceof self => null === $em ? $value->make() : $value->buildIn($em),
                $value instanceof \Closure => $value($sequence),
                default => $value,
            };
        }

        $class = new \ReflectionClass(static::class());
        $arguments = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (\array_key_exists($parameter->getName(), $values)) {
                $arguments[$parameter->getName()] = $values[$parameter->getName()];
                unset($values[$parameter->getName()]);
            } elseif (!$parameter->isOptional()) {
                throw new TestPackException(\sprintf('%s needs a value for the constructor argument $%s of %s: add it to defaults() or to the attributes.', static::class, $parameter->getName(), $class->getName()));
            }
        }
        $entity = $class->newInstanceArgs($arguments);

        foreach ($values as $name => $value) {
            if (!$class->hasProperty($name)) {
                throw new TestPackException(\sprintf('%s sets "%s", which is neither a constructor argument nor a property of %s.', static::class, $name, $class->getName()));
            }
            $property = $class->getProperty($name);
            if ($property->isReadOnly() && $property->isInitialized($entity)) {
                throw new TestPackException(\sprintf('%s cannot set %s::$%s: the readonly property is already written by the constructor.', static::class, $class->getName(), $name));
            }
            $property->setValue($entity, $value);
        }
        $em?->persist($entity);

        return $entity;
    }

    /**
     * Pour une factory d'une autre : persistée dans le même EntityManager, flushée avec elle.
     */
    private function buildIn(EntityManagerInterface $em): object
    {
        return $this->build(null === $this->entityManager ? $em : TestContext::entityManager($this->entityManager));
    }
}
