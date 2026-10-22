<?php

declare(strict_types=1);

namespace Prism\TestPack;

use PHPUnit\Framework\Assert;
use Prism\Bundle\Debug\QueryRecorder;
use Prism\TestPack\Exception\TestPackException;

/**
 * Dans un KernelTestCase ou un WebTestCase : le nombre de requêtes d'un bout de code, pour
 * garder le N+1 hors de l'application (pilier 1). S'appuie sur l'enregistreur du profiler, présent
 * dès que kernel.debug est vrai (le cas par défaut en environnement de test).
 *
 *     $this->assertQueryCount(2, fn () => $client->request('GET', '/orders'));
 *
 * @api
 */
trait QueryCountAssertions
{
    /**
     * @template R
     *
     * @param \Closure(): R $code
     * @param string|null   $connection une connexion ; toutes par défaut
     *
     * @return R ce que rend le code
     */
    public function assertQueryCount(int $expected, \Closure $code, ?string $connection = null, string $message = ''): mixed
    {
        $container = static::getContainer();
        /** @var list<string> $names */
        $names = null === $connection ? $container->getParameter('prism.connection_names') : [$connection];
        $recorders = [];
        foreach ($names as $name) {
            $recorder = $container->has('prism.debug.queries.' . $name) ? $container->get('prism.debug.queries.' . $name) : null;
            if (!$recorder instanceof QueryRecorder) {
                throw new TestPackException(\sprintf('The queries of the connection "%s" are not recorded: assertQueryCount() needs kernel.debug (APP_DEBUG=1, the default in the test environment) and a declared connection.', $name));
            }
            $recorders[$name] = [$recorder, $recorder->getCount(), \count($recorder->getQueries())];
        }

        $result = $code();

        $actual = 0;
        $queries = [];
        foreach ($recorders as $name => [$recorder, $count, $recorded]) {
            $actual += $recorder->getCount() - $count;
            foreach (\array_slice($recorder->getQueries(), $recorded) as $query) {
                $queries[] = \sprintf('[%s] %s', $name, $query['sql']);
            }
        }
        Assert::assertSame($expected, $actual, ('' === $message ? '' : $message . "\n") . \sprintf("%d queries expected, %d executed:\n%s", $expected, $actual, implode("\n", $queries)));

        return $result;
    }
}
