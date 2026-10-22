<?php

declare(strict_types=1);

namespace Prism\Bundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Un repository qui charge lui-même l'utilisateur (identifiant e-mail ou nom, par exemple) :
 * le user provider « prism » sans property l'utilise.
 *
 * @api
 */
interface UserLoaderInterface
{
    public function loadUserByIdentifier(string $identifier): ?UserInterface;
}
