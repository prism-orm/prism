<?php

declare(strict_types=1);

namespace Prism\Orm\Behavior;

use Prism\Dbal\Query\Expression;
use Prism\Orm\Filter\EntityFilter;
use Prism\Orm\Filter\FilterOperation;
use Prism\Orm\Filter\FilterTarget;
use Prism\Orm\Metadata\ClassMetadata;

/**
 * RG-08.14 : les lignes d'une entité #[SoftDeletable] supprimées logiquement sont exclues des
 * lectures (find, findBy, QueryBuilder, collections, comptages). Une écriture les atteint
 * (restore), une référence aussi (ghost, ToOne, refresh) ; ->withDeleted() les réintègre à une
 * requête, getFilters()->disable('soft_delete') à tout l'EntityManager (ADR 0015).
 *
 * @api
 */
final class SoftDeleteFilter implements EntityFilter
{
    public const string NAME = 'soft_delete';

    public function condition(ClassMetadata $metadata, FilterTarget $target): ?Expression
    {
        $field = $metadata->behaviors->deletedAt;
        if (null === $field || FilterOperation::Read !== $target->operation) {
            return null;
        }

        return $target->expr()->isNull($target->column($metadata->getField($field)->columnName));
    }
}
