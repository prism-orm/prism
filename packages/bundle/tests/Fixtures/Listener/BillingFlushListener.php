<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Listener;

use Prism\Bundle\Attribute\AsPrismListener;
use Prism\Orm\Event\PostFlushEvent;
use Prism\Orm\Event\PrePersistEvent;

// une classe : __invoke pour billing seulement ; une méthode : pour tous les EntityManagers
#[AsPrismListener(entityManager: 'billing')]
final readonly class BillingFlushListener
{
    public function __construct(private Journal $journal)
    {
    }

    public function __invoke(PostFlushEvent $event): void
    {
        $this->journal->lines[] = 'billing flush';
    }

    #[AsPrismListener]
    public function onPrePersist(PrePersistEvent $event): void
    {
        $this->journal->lines[] = 'pre-persist event';
    }
}
