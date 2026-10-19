<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures;

use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Mapping as Prism;

// callbacks sur les 7 événements et un listener d'entité ; $log partagé pour vérifier l'ordre
#[Prism\Entity]
#[Prism\HasLifecycleCallbacks]
#[Prism\EntityListeners([JournalListener::class])]
final class Journal
{
    /** @var list<string> */
    public static array $log = [];

    #[Prism\Id, Prism\GeneratedValue(Prism\GenerationStrategy::Identity)]
    public ?int $id = null;

    #[Prism\Column(length: 40)]
    public string $title = '';

    public static function short(object $event): string
    {
        $name = strrchr($event::class, '\\');

        return substr(false === $name ? '\\' . $event::class : $name, 1, -5);
    }

    #[Prism\PrePersist, Prism\PostPersist, Prism\PreUpdate, Prism\PostUpdate, Prism\PreRemove, Prism\PostRemove, Prism\PostLoad]
    public function record(object $event): void
    {
        $changes = $event instanceof PreUpdateEvent ? '(' . implode(',', array_keys($event->changeSet)) . ')' : '';
        self::$log[] = 'callback:' . self::short($event) . $changes;
    }

    // sans argument : l'événement n'est pas transmis
    #[Prism\PrePersist]
    public function touch(): void
    {
        self::$log[] = 'callback:touch';
    }
}
