<?php

declare(strict_types=1);

namespace Prism\Migrations\Operation;

/**
 * Une opération enregistrée par une migration (ADR 0012). Sa description, stable, alimente le
 * checksum (RG-06.4) et sera lue par le linter (RG-06.5).
 *
 * @internal
 */
interface Operation
{
    /**
     * @return array<string, mixed> le nom de l'opération et ses arguments, sans objet
     */
    public function describe(): array;
}
