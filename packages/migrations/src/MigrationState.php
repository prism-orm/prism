<?php

declare(strict_types=1);

namespace Prism\Migrations;

/**
 * @internal
 */
enum MigrationState: string
{
    case Pending = 'pending';
    case Executed = 'executed';
    // exécutée, mais ses opérations ne correspondent plus au checksum (RG-06.4)
    case Modified = 'modified';
    // enregistrée comme exécutée, sans fichier
    case Missing = 'missing';
}
