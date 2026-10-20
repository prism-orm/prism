<?php

declare(strict_types=1);

namespace Prism\PHPStan\Properties;

use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;
use Prism\Orm\Mapping\Column;
use Prism\Orm\Mapping\Embeddable;
use Prism\Orm\Mapping\Entity;
use Prism\PHPStan\MetadataProvider;

/**
 * Les champs, embeddables et relations d'une entité, et les colonnes d'un embeddable, sont
 * écrits par l'hydratation sans constructeur (RG-03.12) et lus par l'UoW : ni « non
 * initialisés », ni « jamais écrits », ni « jamais lus ». Une propriété non mappée reste
 * vérifiée (RG-02.7).
 *
 * @internal
 */
final class MappedPropertiesExtension implements ReadWritePropertiesExtension
{
    public function __construct(private readonly MetadataProvider $metadata)
    {
    }

    public function isAlwaysRead(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isMapped($property, $propertyName);
    }

    public function isAlwaysWritten(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isMapped($property, $propertyName);
    }

    public function isInitialized(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isMapped($property, $propertyName);
    }

    private function isMapped(ExtendedPropertyReflection $property, string $name): bool
    {
        $class = $property->getDeclaringClass();
        $native = $class->getNativeReflection();
        // un embeddable n'a pas de metadata propre : ses colonnes sont hydratées avec l'entité
        if ([] !== $native->getAttributes(Embeddable::class)) {
            return $native->hasProperty($name) && [] !== $native->getProperty($name)->getAttributes(Column::class);
        }
        // l'attribut est lu sans charger la classe : seules les entités le sont, pour leur metadata
        if ([] === $native->getAttributes(Entity::class)) {
            return false;
        }
        $metadata = $this->metadata->metadataFor($class->getName());
        if (null === $metadata) {
            return false;
        }

        return $metadata->hasField($name) || $metadata->hasAssociation($name)
            || [] !== array_filter($metadata->getEmbedded(), static fn ($embedded): bool => $embedded->fieldName === $name);
    }
}
