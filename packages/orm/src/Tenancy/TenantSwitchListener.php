<?php

declare(strict_types=1);

namespace Prism\Orm\Tenancy;

/**
 * Prévenu avant chaque changement effectif de tenant (RG-08.8, ADR 0014) : l'UnitOfWork y vide
 * son identity map pour qu'aucune entité ne passe d'un tenant à l'autre.
 *
 * À l'entrée d'un runAs() ou d'un setTenantId(), une exception empêche le changement. À la
 * sortie d'un runAs(), le contexte est déjà rétabli : le listener remet son propre état en
 * ordre avant de lever ; si le callback a échoué, son exception l'emporte.
 *
 * @api
 */
interface TenantSwitchListener
{
    public function switching(TenantScope $from, TenantScope $to, bool $leaving): void;
}
