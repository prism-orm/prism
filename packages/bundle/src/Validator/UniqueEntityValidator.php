<?php

declare(strict_types=1);

namespace Prism\Bundle\Validator;

use Prism\Orm\EntityManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * #[UniqueEntity] : cherche une autre entité aux mêmes valeurs dans l'EntityManager qui mappe la
 * classe (par ses entity_dirs, ADR 0013), l'entité validée exclue.
 *
 * @internal
 */
final class UniqueEntityValidator extends ConstraintValidator
{
    /**
     * @param ContainerInterface $entityManagers par nom
     * @param list<string>       $names
     */
    public function __construct(
        private readonly ContainerInterface $entityManagers,
        private readonly array $names,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueEntity) {
            throw new UnexpectedTypeException($constraint, UniqueEntity::class);
        }
        if (null === $value) {
            return;
        }
        if (!\is_object($value)) {
            throw new UnexpectedTypeException($value, 'object');
        }
        if ([] === $constraint->fields) {
            throw new ConstraintDefinitionException('#[UniqueEntity] needs at least one field.');
        }
        $em = $this->entityManagerFor($value::class, $constraint->entityManager);
        $metadata = $em->getMetadataFactory()->getMetadataFor($value::class);

        $criteria = [];
        foreach ($constraint->fields as $field) {
            if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
                throw new ConstraintDefinitionException(\sprintf('#[UniqueEntity] on %s names "%s", which is neither a mapped field nor a relation.', $value::class, $field));
            }
            $property = new \ReflectionProperty($value, $field);
            $fieldValue = $property->isInitialized($value) ? $property->getValue($value) : null;
            if (null === $fieldValue && $constraint->ignoreNull) {
                return;
            }
            $criteria[$field] = $fieldValue;
        }

        foreach ($em->getRepository($value::class)->findBy($criteria) as $other) {
            if ($other !== $value) {
                $this->context->buildViolation($constraint->message)
                    ->atPath($constraint->errorPath ?? $constraint->fields[0])
                    ->setParameter('{{ value }}', $this->formatValue($criteria[$constraint->errorPath ?? $constraint->fields[0]] ?? null))
                    ->setInvalidValue($criteria[$constraint->errorPath ?? $constraint->fields[0]] ?? null)
                    ->setCode(UniqueEntity::NOT_UNIQUE_ERROR)
                    ->addViolation();

                return;
            }
        }
    }

    /**
     * @param class-string $class
     */
    private function entityManagerFor(string $class, ?string $name): EntityManager
    {
        foreach (null === $name ? $this->names : [$name] as $candidate) {
            if (!\in_array($candidate, $this->names, true)) {
                throw new ConstraintDefinitionException(\sprintf('#[UniqueEntity(entityManager: "%s")] on %s names an entity manager that is not declared; declared: %s.', $candidate, $class, implode(', ', $this->names)));
            }
            $em = $this->entityManagers->get($candidate);
            \assert($em instanceof EntityManager);
            // ses entity_dirs : isEntity() accepte toute classe #[Entity]
            if (\in_array($class, $em->getMetadataFactory()->getAllClassNames(), true)) {
                return $em;
            }
        }

        throw new ConstraintDefinitionException(\sprintf('#[UniqueEntity] on %s: no entity manager maps this class%s.', $class, null === $name ? '' : ' (' . $name . ')'));
    }
}
