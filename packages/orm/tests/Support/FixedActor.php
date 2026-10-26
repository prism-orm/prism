<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Support;

use Prism\Orm\Behavior\ActorProvider;

/**
 * @internal
 */
final class FixedActor implements ActorProvider
{
    public function __construct(public ?string $actor)
    {
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }
}
