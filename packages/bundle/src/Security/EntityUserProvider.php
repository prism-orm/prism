<?php

declare(strict_types=1);

namespace Prism\Bundle\Security;

use Prism\Orm\EntityManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * User provider « prism » de security.yaml : un utilisateur est une entité, chargée par property
 * (findOneBy) ou par son repository (UserLoaderInterface), rechargée par son identifiant. La mise
 * à niveau du hash de mot de passe est confiée au repository s'il implémente
 * PasswordUpgraderInterface.
 *
 * @template T of UserInterface
 *
 * @implements UserProviderInterface<T>
 *
 * @internal
 */
final class EntityUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    /**
     * @param ContainerInterface $entityManagers par nom
     * @param list<string>       $names
     * @param class-string<T>    $class
     */
    public function __construct(
        private readonly ContainerInterface $entityManagers,
        private readonly array $names,
        private readonly string $class,
        private readonly ?string $property = null,
        private readonly ?string $entityManager = null,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $repository = $this->entityManager()->getRepository($this->class);
        if (null !== $this->property) {
            $user = $repository->findOneBy([$this->property => $identifier]);
        } elseif ($repository instanceof UserLoaderInterface) {
            $user = $repository->loadUserByIdentifier($identifier);
        } else {
            throw new \InvalidArgumentException(\sprintf('The "prism" user provider of %s needs a "property", or a repository that implements %s.', $this->class, UserLoaderInterface::class));
        }
        if (!$user instanceof UserInterface) {
            $exception = new UserNotFoundException(\sprintf('User "%s" not found.', $identifier));
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        /** @var T */
        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$this->supportsClass($user::class)) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', get_debug_type($user)));
        }
        $em = $this->entityManager();
        $field = $em->getMetadataFactory()->getMetadataFor($user::class)->id->fieldName;
        $property = new \ReflectionProperty($user, $field);
        $id = $property->isInitialized($user) ? $property->getValue($user) : null;
        if (null === $id) {
            throw new \InvalidArgumentException(\sprintf('The user %s has no identifier: it cannot be refreshed. Was it persisted?', $user::class));
        }
        $refreshed = $em->find($user::class, $id);
        if (null === $refreshed) {
            $exception = new UserNotFoundException(\sprintf('User %s with id "%s" no longer exists.', $user::class, \is_scalar($id) ? (string) $id : get_debug_type($id)));
            $exception->setUserIdentifier($user->getUserIdentifier());

            throw $exception;
        }

        /** @var T */
        return $refreshed;
    }

    public function supportsClass(string $class): bool
    {
        return $class === $this->class || is_subclass_of($class, $this->class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        $repository = $this->entityManager()->getRepository($this->class);
        if ($repository instanceof PasswordUpgraderInterface) {
            $repository->upgradePassword($user, $newHashedPassword);
        }
    }

    private function entityManager(): EntityManager
    {
        foreach (null === $this->entityManager ? $this->names : [$this->entityManager] as $name) {
            $em = $this->entityManagers->get($name);
            \assert($em instanceof EntityManager);
            // ses entity_dirs : isEntity() accepte toute classe #[Entity]
            if (\in_array($this->class, $em->getMetadataFactory()->getAllClassNames(), true)) {
                return $em;
            }
        }

        throw new \InvalidArgumentException(\sprintf('The "prism" user provider: no entity manager maps %s%s.', $this->class, null === $this->entityManager ? '' : ' (' . $this->entityManager . ')'));
    }
}
