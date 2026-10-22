<?php

declare(strict_types=1);

namespace Prism\TestPack;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Prism\TestPack\Attribute\WithRealTransactions;

/**
 * Dans un KernelTestCase ou un WebTestCase : chaque test part d'une base propre (RG-07.6). Le
 * schéma des entités est créé au premier test ; chaque test tourne dans une transaction annulée à
 * la fin (les flush() de l'application y sont des savepoints, RG-01.1). #[WithRealTransactions]
 * sur le test ou la classe : commits réels, schéma recréé après le test.
 *
 * Sous MySQL et MariaDB, du DDL dans un test fait un commit implicite : ce test doit porter
 * #[WithRealTransactions].
 *
 * @api
 */
trait ResetDatabase
{
    #[Before]
    public function prismResetDatabaseBefore(): void
    {
        TestContext::use(static::getContainer());
        Database::begin($this->prismRealTransactions());
    }

    #[After]
    public function prismResetDatabaseAfter(): void
    {
        Database::end($this->prismRealTransactions());
        TestContext::use(null);
    }

    private function prismRealTransactions(): bool
    {
        $class = new \ReflectionClass($this);

        return [] !== $class->getAttributes(WithRealTransactions::class) || [] !== $class->getMethod($this->name())->getAttributes(WithRealTransactions::class);
    }
}
