<?php

declare(strict_types=1);

namespace Prism\Dbal\Tests\Support;

use PHPUnit\Event\TestRunner\Finished;
use PHPUnit\Event\TestRunner\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * RG-00.1 : la suite crée sa base au démarrage et la détruit à la fin.
 * Sans DATABASE_URL (suite unitaire), l'extension ne fait rien.
 *
 * @internal
 */
final class TestDatabaseExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (null === TestDatabase::url()) {
            return;
        }

        $drop = TestDatabase::isolate();

        $facade->registerSubscriber(new class($drop) implements FinishedSubscriber {
            /**
             * @param \Closure(): void $drop
             */
            public function __construct(private readonly \Closure $drop)
            {
            }

            public function notify(Finished $event): void
            {
                ($this->drop)();
            }
        });
    }
}
