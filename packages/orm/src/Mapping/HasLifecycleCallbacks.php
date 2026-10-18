<?php

declare(strict_types=1);

namespace Prism\Orm\Mapping;

/**
 * Active la lecture des attributs de cycle de vie (#[PrePersist], #[PostLoad]…) sur les méthodes.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class HasLifecycleCallbacks implements MappingAttribute
{
}
