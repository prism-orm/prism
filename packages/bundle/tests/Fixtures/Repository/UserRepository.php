<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Repository;

use Prism\Bundle\Security\UserLoaderInterface;
use Prism\Bundle\Tests\Fixtures\Entity\Shop\User;
use Prism\Orm\Repository\EntityRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends EntityRepository<User>
 */
final class UserRepository extends EntityRepository implements UserLoaderInterface, PasswordUpgraderInterface
{
    // un identifiant insensible à la casse, comme une adresse e-mail
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneBy(['email' => strtolower($identifier)]);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        \assert($user instanceof User);
        // l'appelant flushe : le repository n'a pas accès à l'EntityManager (voir ADR 0006)
        $user->password = $newHashedPassword;
    }
}
