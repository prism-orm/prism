<?php

declare(strict_types=1);

namespace Prism\Orm\Id;

use Prism\Dbal\Platform\AbstractPlatform;
use Prism\Dbal\Platform\PostgreSqlPlatform;
use Prism\Orm\Exception\MappingException;
use Prism\Orm\Mapping\GenerationStrategy;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * Résolution unique de la stratégie (RG-02.4), partagée par le SchemaTool et l'exécution :
 * le schéma créé et les identifiants générés ne peuvent pas diverger.
 *
 * @internal
 */
final class GenerationStrategyResolver
{
    /**
     * Auto : SEQUENCE sous PostgreSQL, IDENTITY ailleurs.
     */
    public static function resolve(?GenerationStrategy $strategy, AbstractPlatform $platform): ?GenerationStrategy
    {
        if (GenerationStrategy::Auto === $strategy) {
            return $platform instanceof PostgreSqlPlatform ? GenerationStrategy::Sequence : GenerationStrategy::Identity;
        }

        return $strategy;
    }

    /**
     * @param ClassMetadata<object> $class
     *
     * @throws MappingException SEQUENCE sur une plateforme sans séquences
     */
    public static function sequenceName(ClassMetadata $class, AbstractPlatform $platform): string
    {
        if (!$platform->supportsSequences()) {
            throw MappingException::invalidField($class->className, $class->id->fieldName, \sprintf('GenerationStrategy::Sequence is not supported by %s; use Auto or Identity.', $platform->getName()));
        }

        return $class->id->sequenceName ?? $class->tableName . '_id_seq';
    }
}
