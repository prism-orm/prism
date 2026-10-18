<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Paramètres communs aux quatre attributs de relation.
 *
 * @api
 */
interface AssociationAttribute extends MappingAttribute
{
    /** @return class-string|null */
    public function getTargetEntity(): ?string;

    /** @return list<Cascade> */
    public function getCascade(): array;

    public function getFetch(): Fetch;
}
