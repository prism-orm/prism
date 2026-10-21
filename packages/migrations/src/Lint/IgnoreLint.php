<?php

declare(strict_types=1);

namespace Prism\Migrations\Lint;

/**
 * Ignore une règle du linter pour une migration, quand le cas est assumé ; la raison est
 * obligatoire et affichée par db:migrations:lint.
 *
 *     #[IgnoreLint('L12', reason: 'legacy_fax is empty in production')]
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class IgnoreLint
{
    public function __construct(
        public readonly string $code,
        public readonly string $reason,
    ) {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException(\sprintf('#[IgnoreLint(\'%s\')] needs a reason.', $code));
        }
    }
}
