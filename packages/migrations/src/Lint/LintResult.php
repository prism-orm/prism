<?php

declare(strict_types=1);

namespace Prism\Migrations\Lint;

/**
 * @api
 */
final class LintResult
{
    /**
     * @param list<string>  $versions       les migrations analysées
     * @param list<Finding> $findings       dans l'ordre des migrations, puis des opérations
     * @param bool          $mappingChecked faux sans mapping : L03 à L05 n'ont pas été vérifiées
     */
    public function __construct(
        public readonly array $versions,
        public readonly array $findings,
        public readonly bool $mappingChecked,
    ) {
    }

    /**
     * @return list<Finding> les cas non ignorés de cette sévérité
     */
    public function active(Severity $severity): array
    {
        return array_values(array_filter($this->findings, static fn (Finding $f): bool => $severity === $f->severity && null === $f->ignoredBecause));
    }
}
