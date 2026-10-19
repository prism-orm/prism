<?php

declare(strict_types=1);

namespace Prism\Orm\Id;

use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Un générateur par entité, selon sa stratégie résolue pour la plateforme ; null pour un
 * identifiant assigné par l'application.
 *
 * @internal
 */
final class IdGeneratorFactory
{
    /** @var array<class-string, IdGenerator|null> */
    private array $generators = [];

    private ?UuidV7Generator $uuid = null;

    public function __construct(private readonly AbstractPlatform $platform)
    {
    }

    /**
     * @param ClassMetadata<object> $class
     *
     * @throws MappingException SEQUENCE sur une plateforme sans séquences
     */
    public function getGenerator(ClassMetadata $class): ?IdGenerator
    {
        if (\array_key_exists($class->className, $this->generators)) {
            return $this->generators[$class->className];
        }

        return $this->generators[$class->className] = match (GenerationStrategyResolver::resolve($class->id->strategy, $this->platform)) {
            null => null,
            GenerationStrategy::Identity => new IdentityGenerator(),
            GenerationStrategy::Sequence => new SequenceGenerator(GenerationStrategyResolver::sequenceName($class, $this->platform)),
            // un seul générateur UUID : la monotonie vaut pour toutes les entités du processus
            GenerationStrategy::UuidV7 => $this->uuid ??= new UuidV7Generator(),
            GenerationStrategy::Auto => throw new \LogicException('Auto is resolved by GenerationStrategyResolver.'),
        };
    }
}
