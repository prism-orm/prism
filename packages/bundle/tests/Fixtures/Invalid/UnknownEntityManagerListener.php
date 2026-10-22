<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Invalid;

use Prism\Bundle\Attribute\AsPrismListener;
use Prism\Orm\Event\PostFlushEvent;

#[AsPrismListener(entityManager: 'archive')]
final class UnknownEntityManagerListener
{
    public function __invoke(PostFlushEvent $event): void
    {
    }
}
