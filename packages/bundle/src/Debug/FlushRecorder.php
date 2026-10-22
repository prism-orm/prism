<?php

declare(strict_types=1);

namespace Prism\Bundle\Debug;

use Prism\Orm\Event\BatchLoadedEvent;
use Prism\Orm\Event\PostFlushEvent;
use Prism\Orm\Event\PostPersistEvent;
use Prism\Orm\Event\PostRemoveEvent;
use Prism\Orm\Event\PreUpdateEvent;
use Prism\Orm\Metadata\MetadataFactory;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ce que fait un EntityManager pendant une requête HTTP, pour le profiler (dev seulement) : les
 * flushs avec leurs insertions, mises à jour (changeset) et suppressions, et les chargements par
 * lot (cohortes). Les valeurs sont réduites à du texte : le profil est sérialisé.
 *
 * @internal
 */
final class FlushRecorder implements ResetInterface
{
    /** @var list<list<array{action: string, class: string, id: string, changes: array<string, array{string, string}>}>> */
    private array $flushes = [];

    /** @var list<array{action: string, class: string, id: string, changes: array<string, array{string, string}>}> */
    private array $current = [];

    /** @var list<array{class: string, association: ?string, count: int}> */
    private array $batches = [];

    public function __construct(private readonly MetadataFactory $metadata)
    {
    }

    public function onPostPersist(PostPersistEvent $event): void
    {
        $this->record('insert', $event->entity);
    }

    public function onPreUpdate(PreUpdateEvent $event): void
    {
        $this->record('update', $event->entity, array_map(static fn (array $change): array => [self::text($change[0]), self::text($change[1])], $event->changeSet));
    }

    public function onPostRemove(PostRemoveEvent $event): void
    {
        $this->record('delete', $event->entity);
    }

    public function onPostFlush(PostFlushEvent $event): void
    {
        $this->flushes[] = $this->current;
        $this->current = [];
    }

    public function onBatchLoaded(BatchLoadedEvent $event): void
    {
        $this->batches[] = ['class' => $event->entityClass, 'association' => $event->association, 'count' => $event->count];
    }

    /**
     * @return list<list<array{action: string, class: string, id: string, changes: array<string, array{string, string}>}>>
     */
    public function getFlushes(): array
    {
        return $this->flushes;
    }

    /**
     * @return list<array{class: string, association: ?string, count: int}>
     */
    public function getBatches(): array
    {
        return $this->batches;
    }

    public function reset(): void
    {
        $this->flushes = [];
        $this->current = [];
        $this->batches = [];
    }

    /**
     * @param array<string, array{string, string}> $changes
     */
    private function record(string $action, object $entity, array $changes = []): void
    {
        $this->current[] = ['action' => $action, 'class' => $entity::class, 'id' => $this->idOf($entity), 'changes' => $changes];
    }

    // lu sans initialiser l'entité ; vide pour un identifiant pas encore généré
    private function idOf(object $entity): string
    {
        $field = $this->metadata->getMetadataFor($entity::class)->id->fieldName;
        $property = new \ReflectionProperty($entity, $field);

        return $property->isInitialized($entity) ? self::text($property->getRawValue($entity)) : '';
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            $value instanceof \BackedEnum => $value::class . '::' . $value->name,
            \is_array($value) => (string) json_encode($value),
            default => get_debug_type($value),
        };
    }
}
