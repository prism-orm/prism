<?php

declare(strict_types=1);

namespace Prism\Bundle\ValueResolver;

use Prism\Bundle\Attribute\MapEntity;
use Prism\Bundle\Exception\MapEntityException;
use Prism\Dbal\Exception\ConversionException;
use Prism\Orm\EntityManager;
use Prism\Orm\Metadata\FieldMapping;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Un argument de contrôleur typé par une entité, chargé depuis un paramètre de route (RG-07.3) :
 * 404 si l'entité n'existe pas ou si l'identifiant ne peut pas en être un, null pour un
 * argument nullable. Aucune correspondance automatique des autres paramètres de route.
 *
 * @internal
 */
final class EntityValueResolver implements ValueResolverInterface
{
    private const array INTEGER_TYPES = ['integer', 'smallint', 'bigint'];

    /**
     * @param ContainerInterface $entityManagers les EntityManagers, par nom
     * @param list<string>       $names          dans l'ordre de déclaration
     */
    public function __construct(
        private readonly ContainerInterface $entityManagers,
        private readonly array $names,
    ) {
    }

    /**
     * @return iterable<object|null>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $options = $argument->getAttributesOfType(MapEntity::class, ArgumentMetadata::IS_INSTANCEOF)[0] ?? new MapEntity();
        $class = $argument->getType();
        if ($options->disabled || null === $class || !class_exists($class)) {
            return [];
        }
        $em = $this->entityManagerFor($class, $options);
        if (null === $em) {
            return [];
        }
        $metadata = $em->getMetadataFactory()->getMetadataFor($class);

        if (null !== $options->mapping) {
            $criteria = [];
            foreach ($options->mapping as $parameter => $field) {
                if (!$request->attributes->has($parameter)) {
                    throw new MapEntityException(\sprintf('#[MapEntity] of the argument $%s maps the route parameter "%s", which the route does not have.', $argument->getName(), $parameter));
                }
                if (!$metadata->hasField($field)) {
                    throw new MapEntityException(\sprintf('#[MapEntity] of the argument $%s maps "%s" to %s::$%s, which is not a mapped field.', $argument->getName(), $parameter, $class, $field));
                }
                $value = self::convert($metadata->getField($field), $request->attributes->get($parameter));
                if (null === $value) {
                    return $this->notFound($argument, $class, $criteria + [$field => $request->attributes->get($parameter)]);
                }
                $criteria[$field] = $value;
            }

            return $this->found($argument, $class, self::attempt(static fn (): ?object => $em->getRepository($class)->findOneBy($criteria)), $criteria);
        }

        $parameter = $options->id ?? ($request->attributes->has($argument->getName()) ? $argument->getName() : 'id');
        if (!$request->attributes->has($parameter)) {
            if (null !== $options->id) {
                throw new MapEntityException(\sprintf('#[MapEntity(id: "%s")] of the argument $%s names a route parameter that the route does not have.', $parameter, $argument->getName()));
            }

            return [];
        }
        $raw = $request->attributes->get($parameter);
        $id = self::convert($metadata->getIdField(), $raw);

        return $this->found($argument, $class, null === $id ? null : self::attempt(static fn (): ?object => $em->find($class, $id)), ['id' => $raw]);
    }

    /**
     * @param class-string $class
     */
    private function entityManagerFor(string $class, MapEntity $options): ?EntityManager
    {
        if (null !== $options->entityManager) {
            if (!\in_array($options->entityManager, $this->names, true)) {
                throw new MapEntityException(\sprintf('#[MapEntity(entityManager: "%s")] names an entity manager that is not declared; declared: %s.', $options->entityManager, implode(', ', $this->names)));
            }

            $em = self::entityManager($this->entityManagers->get($options->entityManager));
            if (!self::maps($em, $class)) {
                throw new MapEntityException(\sprintf('#[MapEntity(entityManager: "%s")] names an entity manager that does not map %s.', $options->entityManager, $class));
            }

            return $em;
        }
        foreach ($this->names as $name) {
            $em = self::entityManager($this->entityManagers->get($name));
            if (self::maps($em, $class)) {
                return $em;
            }
        }

        return null;
    }

    // les entités de ses entity_dirs : isEntity() accepte toute classe #[Entity], d'où qu'elle vienne
    private static function maps(EntityManager $em, string $class): bool
    {
        return \in_array($class, $em->getMetadataFactory()->getAllClassNames(), true);
    }

    private static function entityManager(mixed $em): EntityManager
    {
        \assert($em instanceof EntityManager);

        return $em;
    }

    /**
     * Un paramètre de route est une chaîne : null s'il ne peut pas être une valeur du champ.
     */
    private static function convert(FieldMapping $field, mixed $value): mixed
    {
        if (\in_array($field->type, self::INTEGER_TYPES, true)) {
            return \is_int($value) || (\is_string($value) && 1 === preg_match('/^-?[0-9]{1,18}$/', $value)) ? (int) $value : null;
        }

        return \is_scalar($value) ? $value : null;
    }

    // un uuid mal formé, ou tout identifiant que son Type refuse : l'entité n'existe pas
    private static function attempt(\Closure $find): ?object
    {
        try {
            $entity = $find();
        } catch (ConversionException) {
            return null;
        }

        return \is_object($entity) ? $entity : null;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<object|null>
     */
    private function found(ArgumentMetadata $argument, string $class, ?object $entity, array $criteria): array
    {
        return null === $entity ? $this->notFound($argument, $class, $criteria) : [$entity];
    }

    /**
     * RG-07.3 : 404, sauf argument nullable.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<null>
     */
    private function notFound(ArgumentMetadata $argument, string $class, array $criteria): array
    {
        if ($argument->isNullable()) {
            return [null];
        }

        throw new NotFoundHttpException(\sprintf('%s not found (%s).', $class, implode(', ', array_map(static fn (string $k, mixed $v): string => $k . ' = ' . (\is_scalar($v) ? (string) $v : get_debug_type($v)), array_keys($criteria), $criteria))));
    }
}
