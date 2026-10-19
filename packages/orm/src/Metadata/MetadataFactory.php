<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

use Prism\Dbal\Type\TypeRegistry;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Metadata\Driver\MetadataDriver;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Point d'accès aux metadata : mémoire, puis cache PSR-6, puis driver.
 *
 * Sans cache (dev), les attributs sont relus par chaque nouvelle factory. Avec un cache (prod),
 * warmUp() valide puis enregistre toutes les entités : ensuite plus aucun attribut n'est lu.
 *
 * @api
 */
final class MetadataFactory
{
    private const string PREFIX = 'prism.metadata.';

    private const string CLASS_LIST_KEY = 'prism.metadata.__entities';

    /** @var array<class-string, ClassMetadata<object>> */
    private array $loaded = [];

    /** @var list<class-string>|null */
    private ?array $classNames = null;

    private readonly MetadataValidator $validator;

    public function __construct(
        private readonly MetadataDriver $driver,
        private readonly ?CacheItemPoolInterface $cache = null,
        ?TypeRegistry $types = null,
    ) {
        $this->validator = new MetadataValidator($types);
    }

    /**
     * Une même classe renvoie toujours la même instance.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return ClassMetadata<T>
     *
     * @throws MappingException
     */
    public function getMetadataFor(string $class): ClassMetadata
    {
        if (!isset($this->loaded[$class])) {
            $cached = $this->fromCache(self::key($class));
            $this->loaded[$class] = $cached instanceof ClassMetadata && $cached->className === $class
                ? $cached
                : $this->save(self::key($class), $this->driver->loadMetadata($class));
        }

        /** @var ClassMetadata<T> */
        return $this->loaded[$class];
    }

    /**
     * @return list<ClassMetadata<object>>
     *
     * @throws MappingException
     */
    public function getAllMetadata(): array
    {
        return array_map($this->getMetadataFor(...), $this->getAllClassNames());
    }

    /**
     * @return list<class-string>
     */
    public function getAllClassNames(): array
    {
        if (null === $this->classNames) {
            $cached = $this->fromCache(self::CLASS_LIST_KEY);
            $this->classNames = self::isClassList($cached) ? $cached : $this->save(self::CLASS_LIST_KEY, $this->driver->getAllClassNames());
        }

        return $this->classNames;
    }

    /**
     * @param class-string $class
     */
    public function isEntity(string $class): bool
    {
        return isset($this->loaded[$class]) || \in_array($class, $this->getAllClassNames(), true) || $this->driver->isEntity($class);
    }

    /**
     * Lit, valide puis met en cache toutes les entités. Un mapping invalide n'écrit rien.
     *
     * @throws MappingException toutes les erreurs de mapping
     */
    public function warmUp(): void
    {
        $classNames = $this->driver->getAllClassNames();
        $metadata = array_map(fn (string $class): ClassMetadata => $this->driver->loadMetadata($class), $classNames);
        $this->validator->assertValid($metadata);

        $this->loaded = [];
        foreach ($metadata as $class) {
            $this->loaded[$class->className] = $this->save(self::key($class->className), $class);
        }
        $this->classNames = $this->save(self::CLASS_LIST_KEY, $classNames);
    }

    /**
     * Oublie les metadata en mémoire et supprime celles du cache (et seulement elles).
     */
    public function clear(): void
    {
        $keys = array_map(self::key(...), array_unique([...array_keys($this->loaded), ...$this->getAllClassNames()]));
        $this->cache?->deleteItems([...$keys, self::CLASS_LIST_KEY]);
        $this->loaded = [];
        $this->classNames = null;
    }

    /**
     * Une entrée illisible (version de Prism différente, classe renommée) est ignorée : le driver
     * relit les attributs au lieu de faire échouer l'application.
     */
    private function fromCache(string $key): mixed
    {
        if (null === $this->cache) {
            return null;
        }

        try {
            $item = $this->cache->getItem($key);

            return $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @template V
     *
     * @param V $value
     *
     * @return V
     */
    private function save(string $key, mixed $value): mixed
    {
        if (null !== $this->cache) {
            $this->cache->save($this->cache->getItem($key)->set($value));
        }

        return $value;
    }

    /**
     * @phpstan-assert-if-true list<class-string> $value
     */
    private static function isClassList(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $class) {
            if (!\is_string($class) || !class_exists($class)) {
                return false;
            }
        }

        return true;
    }

    // les caractères {}()/\@: sont interdits dans une clé PSR-6
    private static function key(string $class): string
    {
        return self::PREFIX . str_replace('\\', '.', $class);
    }
}
