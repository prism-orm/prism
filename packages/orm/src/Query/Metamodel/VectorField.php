<?php

declare(strict_types=1);

namespace Prism\Orm\Query\Metamodel;

use Prism\Dbal\Schema\IndexKind;
use Prism\Orm\Query\Expression\FloatExpression;
use Prism\Orm\Query\QueryContext;
use Prism\Orm\Vector\Distance;
use Prism\Orm\Vector\Vector;

/**
 * Champ vecteur (pilier 4, ADR 0017) : sa distance à un vecteur se trie et se sélectionne. Plus
 * petite = plus proche ; sans distance donnée, celle du #[VectorIndex] du champ, sinon le cosinus.
 *
 *     $d = Doc_::as('d');
 *     $em->query($d)->orderBy($d->embedding->distanceTo($query)->asc())->limit(5)->getResult();
 *
 * @api
 */
final class VectorField extends Field
{
    /**
     * @param Vector|list<float> $vector
     */
    public function distanceTo(Vector|array $vector, ?Distance $distance = null): FloatExpression
    {
        $values = $vector instanceof Vector ? $vector->toArray() : $vector;

        return new FloatExpression(
            fn (QueryContext $c): string => $c->platform()->getVectorDistanceSql(
                ($distance ?? $this->indexDistance($c))->toDbal(),
                $this->toSql($c),
                $c->bind($values, 'vector'),
            ),
            static fn (): string => 'float',
        );
    }

    private function indexDistance(QueryContext $context): Distance
    {
        foreach ($context->metadataOf($this->owner)->indexes as $index) {
            if (IndexKind::Vector === $index->kind && [$this->path] === $index->fields) {
                return $index->distance ?? Distance::Cosine;
            }
        }

        return Distance::Cosine;
    }
}
