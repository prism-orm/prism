<?php

declare(strict_types=1);

namespace Prism\TestPack;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Prism\TestPack\Attribute\WithRealTransactions;
use Prism\TestPack\Exception\TestPackException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Dans un KernelTestCase ou un WebTestCase : chaque test part d'une base propre (RG-07.6). Le
 * schéma des entités est créé au premier test ; chaque test tourne dans une transaction annulée à
 * la fin (les flush() de l'application y sont des savepoints, RG-01.1). #[WithRealTransactions]
 * sur le test ou la classe : commits réels, schéma recréé après le test.
 *
 * Sous MySQL et MariaDB, du DDL dans un test fait un commit implicite : ce test doit porter
 * #[WithRealTransactions]. Dans un WebTestCase, createClient() sert le kernel du test (voir plus bas).
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

    /**
     * Pour un WebTestCase : le client sert le kernel déjà démarré par ResetDatabase, et ne le
     * redémarre pas entre deux requêtes, pour que toutes passent par la connexion du test et sa
     * transaction. Remplace WebTestCase::createClient(), qui refuse un kernel déjà démarré.
     *
     * @param array<mixed> $options
     * @param array<mixed> $server
     */
    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        if ([] !== $options) {
            throw new TestPackException('With ResetDatabase, the kernel is booted before the test: choose its environment with APP_ENV, or by overriding createKernel(), not with createClient() options.');
        }
        $container = static::getContainer();
        $client = $container->has('test.client') ? $container->get('test.client') : null;
        if (!$client instanceof KernelBrowser) {
            throw new TestPackException('No test client: set framework.test to true and install symfony/browser-kit.');
        }
        $client->disableReboot();
        $client->setServerParameters($server);
        // WebTestCase garde le client pour ses assertions (assertResponseIsSuccessful…)
        if (method_exists(static::class, 'getClient')) {
            new \ReflectionMethod(static::class, 'getClient')->invoke(null, $client);
        }

        return $client;
    }

    private function prismRealTransactions(): bool
    {
        $class = new \ReflectionClass($this);

        return [] !== $class->getAttributes(WithRealTransactions::class) || [] !== $class->getMethod($this->name())->getAttributes(WithRealTransactions::class);
    }
}
