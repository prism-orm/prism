<?php

declare(strict_types=1);

namespace Prism\Migrations\Lint;

/**
 * Un cas dangereux trouvé dans une migration en attente (RG-06.5).
 *
 * @api
 */
final class Finding
{
    public function __construct(
        public readonly string $version,
        public readonly string $code,
        public readonly Severity $severity,
        public readonly string $message,
        // la raison de #[IgnoreLint], quand il est ignoré
        public readonly ?string $ignoredBecause = null,
    ) {
    }
}
