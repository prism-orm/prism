<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

use Prism\Orm\Exception\MappingException;

/**
 * Stratégie de génération de l'identifiant.
 *
 * Auto : Identity sous MySQL et MariaDB, Sequence sous PostgreSQL.
 * UuidV7 : identifiant généré côté PHP avant l'INSERT (propriété de type Uuid).
 * sequenceName : uniquement avec Auto ou Sequence ; par défaut <table>_id_seq.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class GeneratedValue implements MappingAttribute
{
    public readonly ?string $sequenceName;

    public function __construct(
        public readonly GenerationStrategy $strategy = GenerationStrategy::Auto,
        ?string $sequenceName = null,
    ) {
        if (null !== $sequenceName && !\in_array($strategy, [GenerationStrategy::Auto, GenerationStrategy::Sequence], true)) {
            throw MappingException::invalidAttributeArgument('GeneratedValue', 'sequenceName', 'only allowed with GenerationStrategy::Auto or GenerationStrategy::Sequence.');
        }
        $this->sequenceName = AttributeGuard::name('GeneratedValue', 'sequenceName', $sequenceName);
    }
}
