<?php

declare(strict_types=1);

namespace Prism\PHPStan;

use Prism\Orm\Metadata\ClassMetadata;
use Prism\Orm\Metadata\Driver\AttributeDriver;
use Prism\Orm\Metadata\MetadataFactory;
use Prism\Orm\Query\Metamodel\MetamodelAutoloader;

/**
 * Le mapping des entités, lu par Prism lui-même (ADR 0011) : mêmes déductions qu'à l'exécution.
 * Une classe qui ne se charge pas, ou dont le mapping est invalide, n'a pas de metadata :
 * l'extension n'en dit rien, db:schema:validate s'en charge.
 *
 * Créé avec les règles, avant l'analyse : y enregistre le chargement du metamodel généré.
 *
 * @internal
 */
final class MetadataProvider
{
    private readonly MetadataFactory $factory;

    /** @var array<string, ClassMetadata<object>|null> */
    private array $metadata = [];

    public function __construct(string $metamodelDir)
    {
        if (is_dir($metamodelDir)) {
            MetamodelAutoloader::register($metamodelDir);
        }
        $this->factory = new MetadataFactory(new AttributeDriver());
    }

    /**
     * @return ClassMetadata<object>|null
     */
    public function metadataFor(string $class): ?ClassMetadata
    {
        if (!\array_key_exists($class, $this->metadata)) {
            try {
                $this->metadata[$class] = class_exists($class) ? $this->factory->getMetadataFor($class) : null;
            } catch (\Throwable) {
                $this->metadata[$class] = null;
            }
        }

        return $this->metadata[$class];
    }

    /**
     * Les chemins comparables d'une entité : champs, champs d'embeddable, relations ToOne possédées.
     *
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    public static function comparablePaths(ClassMetadata $metadata): array
    {
        $paths = array_map(static fn ($field): string => $field->fieldName, $metadata->getFields());
        foreach ($metadata->getEmbedded() as $embedded) {
            foreach ($embedded->getFields() as $field) {
                $paths[] = $embedded->fieldName . '.' . $field->fieldName;
            }
        }
        foreach ($metadata->getAssociations() as $association) {
            if ($association->isToOne() && $association->isOwningSide()) {
                $paths[] = $association->fieldName;
            }
        }

        return $paths;
    }

    /**
     * Le chemin connu le plus proche d'une faute de frappe, s'il y en a un.
     *
     * @param list<string> $paths
     */
    public static function closest(string $path, array $paths): ?string
    {
        $best = null;
        $distance = 4;
        foreach ($paths as $candidate) {
            $d = levenshtein(strtolower($path), strtolower($candidate));
            if ($d < $distance) {
                [$best, $distance] = [$candidate, $d];
            }
        }

        return $best;
    }
}
