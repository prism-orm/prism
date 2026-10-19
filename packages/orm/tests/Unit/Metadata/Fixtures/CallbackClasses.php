<?php

declare(strict_types=1);

/**
 * Callbacks et listeners aux signatures valides et invalides ; chargés par MetadataValidatorTest
 * (pas d'autoload).
 */

namespace Prism\Orm\Tests\Unit\Metadata\Fixtures\Callbacks;

use Prism\Orm\Event\PostLoadEvent;
use Prism\Orm\Event\PostPersistEvent;
use Prism\Orm\Event\PrePersistEvent;

final class CallbackEntity
{
    public function noArgument(): void
    {
    }

    public function anyObject(object $event): void
    {
    }

    public function union(PrePersistEvent|PostPersistEvent $event): void
    {
    }

    public function wrongType(PostPersistEvent $event): void
    {
    }

    public function tooMany(PrePersistEvent $event, int $extra): void
    {
    }

    public static function shared(): void
    {
    }

    private function hidden(): void
    {
    }

    public function callsHidden(): void
    {
        $this->hidden();
    }
}

final class ListenedEntity
{
}

final class EmptyListener
{
}

final class CheckedListener
{
    public function valid(ListenedEntity $entity, PrePersistEvent $event): void
    {
    }

    public function wrongEntity(CallbackEntity $entity): void
    {
    }

    public function wrongEvent(object $entity, PostPersistEvent $event): void
    {
    }

    public function tooMany(object $entity, object $event, object $extra): void
    {
    }

    private function hidden(object $entity): void
    {
    }

    public function callsHidden(): void
    {
        $this->hidden(new ListenedEntity());
    }

    public function load(ListenedEntity $entity, PostLoadEvent $event): void
    {
    }
}
