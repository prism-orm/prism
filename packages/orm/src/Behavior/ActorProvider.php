<?php

declare(strict_types=1);

namespace Prism\Orm\Behavior;

/**
 * L'auteur des modifications, pour #[Blameable] et le journal d'audit (RG-08.16, ADR 0015) :
 * l'identifiant de l'utilisateur connecté, « cli:<commande> »… null : aucun auteur connu.
 *
 * @api
 */
interface ActorProvider
{
    public function getActor(): ?string;
}
