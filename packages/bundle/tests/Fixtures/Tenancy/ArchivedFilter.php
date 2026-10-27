<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Fixtures\Tenancy;

use Prism\Dbal\Query\Expression;
use Prism\Orm\Filter\EntityFilter;
use Prism\Orm\Filter\FilterTarget;
use Prism\Orm\Metadata\ClassMetadata;

// un filtre de l'application, autoconfiguré (tag prism.filter) : les notes archivées sont cachées
final class ArchivedFilter implements EntityFilter
{
    public function condition(ClassMetadata $metadata, FilterTarget $target): ?Expression
    {
        return Note::class === $metadata->className ? $target->expr()->neq($target->column('title'), 'archived') : null;
    }
}
