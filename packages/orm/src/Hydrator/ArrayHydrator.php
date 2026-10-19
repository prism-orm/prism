<?php

declare(strict_types=1);

namespace Prism\Orm\Hydrator;

use Prism\Orm\Metadata\ClassMetadata;

/**
 * Lignes en tableaux en lecture seule, indexés par nom de champ, sans entité ni UnitOfWork :
 * pour les listes et les exports, où suivre les changements n'aurait aucun sens.
 *
 * @internal
 */
final class ArrayHydrator
{
    public function __construct(private readonly ObjectHydrator $converter)
    {
    }

    /**
     * Un embeddable devient un sous-tableau, ou null si toutes ses colonnes sont NULL (RG-02.5).
     *
     * @param ClassMetadata<object> $metadata
     * @param array<string, mixed>  $row      ligne telle que la base la renvoie
     *
     * @return array<string, mixed>
     */
    public function hydrate(ClassMetadata $metadata, array $row): array
    {
        $values = $this->converter->toPhpRow($metadata, $row);
        $result = [];

        foreach ($metadata->getFields() as $field) {
            if (\array_key_exists($field->columnName, $values)) {
                $result[$field->fieldName] = $metadata->toFieldValue($field, $values[$field->columnName]);
            }
        }
        foreach ($metadata->getEmbedded() as $embedded) {
            $fields = array_filter($embedded->getFields(), static fn ($f): bool => \array_key_exists($f->columnName, $values));
            if ([] === $fields) {
                continue;
            }
            $nested = [];
            foreach ($fields as $field) {
                $nested[$field->fieldName] = $metadata->toFieldValue($field, $values[$field->columnName]);
            }
            $result[$embedded->fieldName] = $embedded->nullable && [] === array_filter($nested, static fn (mixed $v): bool => null !== $v) ? null : $nested;
        }

        return $result;
    }

    /**
     * @param ClassMetadata<object>      $metadata
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function hydrateAll(ClassMetadata $metadata, array $rows): array
    {
        return array_map(fn (array $row): array => $this->hydrate($metadata, $row), $rows);
    }
}
