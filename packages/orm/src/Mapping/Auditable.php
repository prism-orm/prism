<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Orm\Exception\MappingException;

/**
 * Journalise chaque insert, update, delete, soft delete et restore dans prism_audit_log,
 * dans la transaction du flush (pilier 3).
 *
 * - exclude : champs ignorés (ex. updatedAt) ;
 * - retention : durée ISO 8601 de conservation (ex. 'P10Y'), utilisée par db:audit:purge.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Auditable implements MappingAttribute
{
    /** @var list<string> */
    public readonly array $exclude;

    public readonly ?string $retention;

    /**
     * @param list<string> $exclude
     */
    public function __construct(array $exclude = [], ?string $retention = null)
    {
        $this->exclude = [] === $exclude ? [] : AttributeGuard::fieldList('Auditable', 'exclude', $exclude);

        if (null !== $retention) {
            try {
                new \DateInterval($retention);
            } catch (\Exception) {
                throw MappingException::invalidAttributeArgument('Auditable', 'retention', \sprintf('"%s" is not a valid ISO 8601 duration (ex. P10Y, P6M).', $retention));
            }
        }
        $this->retention = $retention;
    }
}
