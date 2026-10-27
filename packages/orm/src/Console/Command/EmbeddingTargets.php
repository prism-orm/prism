<?php

declare(strict_types=1);

namespace Prism\Orm\Console\Command;

use Prism\Orm\Metadata\MetadataFactory;

/**
 * Les champs #[EmbeddedVector] visés par db:embeddings:* : tous, ou ceux d'une entité nommée
 * par sa classe ou son nom court.
 *
 * @internal
 */
final class EmbeddingTargets
{
    private function __construct()
    {
    }

    /**
     * @return list<array{class-string, string}> classe et champ
     */
    public static function find(MetadataFactory $metadata, ?string $only): array
    {
        $targets = [];
        foreach ($metadata->getAllMetadata() as $class) {
            $separator = strrpos($class->className, '\\');
            $short = false === $separator ? $class->className : substr($class->className, $separator + 1);
            if (null !== $only && 0 !== strcasecmp(ltrim($only, '\\'), $class->className) && 0 !== strcasecmp($only, $short)) {
                continue;
            }
            foreach ($class->getEmbeddings() as $embedding) {
                $targets[] = [$class->className, $embedding->field];
            }
        }

        return $targets;
    }
}
