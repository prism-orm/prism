<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Entity\Shop;

use Prism\Bundle\Tests\Fixtures\Repository\UserRepository;
use Prism\Bundle\Validator\UniqueEntity;
use Prism\Orm\Mapping as Prism;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[Prism\Entity(repositoryClass: UserRepository::class)]
#[Prism\Table('app_user')]
#[UniqueEntity(fields: ['email'])]
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Column(length: 180, unique: true)]
    public string $email = '';

    #[Prism\Column(length: 100)]
    public string $password = '';

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }
}
