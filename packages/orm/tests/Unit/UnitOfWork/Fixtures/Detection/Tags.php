<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\UnitOfWork\Fixtures\Detection;

// un objet mutable, porté par un type personnalisé : modifié sur place, il garde son identité
final class Tags
{
    /**
     * @param list<string> $items
     */
    public function __construct(public array $items = [])
    {
    }
}
