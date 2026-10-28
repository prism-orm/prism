<?php

declare(strict_types=1);

namespace Prism\Migrations\Lint;

/**
 * @internal
 */
enum Severity: string
{
    // la migration échouera, ou cassera l'application
    case Error = 'error';
    // la migration passera, à un coût ou un risque à connaître
    case Warning = 'warning';
}
