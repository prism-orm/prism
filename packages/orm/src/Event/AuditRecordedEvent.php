<?php

declare(strict_types=1);

namespace Prism\Orm\Event;

use Prism\Orm\Audit\AuditEntry;

/**
 * Les lignes d'audit d'un flush, publiées après son commit (ADR 0015) ; le panneau Audit du
 * profiler les écoute.
 *
 * @api
 */
final class AuditRecordedEvent
{
    /**
     * @param list<AuditEntry> $entries
     */
    public function __construct(
        public readonly array $entries,
    ) {
    }
}
