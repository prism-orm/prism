<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Tenancy\StackTenantContext;
use Prism\Orm\Tenancy\TenantScope;
use Prism\Orm\Tenancy\TenantSwitchListener;

/**
 * Contexte de tenant en pile (ADR 0014) : runAs() et runAsSystem() rétablissent toujours
 * l'état précédent, même en cas d'exception (RG-08.5).
 */
final class StackTenantContextTest extends TestCase
{
    // RG-08.4 : vide par défaut, sans mode « tous les tenants »
    public function testEmptyByDefault(): void
    {
        $context = new StackTenantContext();

        self::assertNull($context->getTenantId());
        self::assertFalse($context->isSystem());
    }

    public function testBaseTenant(): void
    {
        $context = new StackTenantContext(7);
        self::assertSame(7, $context->getTenantId());

        $context->setTenantId('acme');
        self::assertSame('acme', $context->getTenantId());
    }

    public function testRunAsStacksAndRestores(): void
    {
        $context = new StackTenantContext(1);

        $seen = $context->runAs(2, static fn (): array => [$context->getTenantId(), $context->runAs(3, $context->getTenantId(...)), $context->getTenantId()]);

        self::assertSame([2, 3, 2], $seen);
        self::assertSame(1, $context->getTenantId());
        self::assertFalse($context->isSystem());
    }

    // RG-08.5
    public function testRunAsSystemHasNoTenant(): void
    {
        $context = new StackTenantContext(1);

        $seen = $context->runAsSystem(static fn (): array => [$context->getTenantId(), $context->isSystem(), $context->runAs(2, static fn (): array => [$context->getTenantId(), $context->isSystem()])]);

        self::assertSame([null, true, [2, false]], $seen, 'runAs() inside the system mode acts for one tenant');
        self::assertSame(1, $context->getTenantId());
        self::assertFalse($context->isSystem());
    }

    public function testStateIsRestoredAfterAnException(): void
    {
        $context = new StackTenantContext(1);

        try {
            $context->runAsSystem(static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        try {
            $context->runAs(2, static fn (): never => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        self::assertSame(1, $context->getTenantId());
        self::assertFalse($context->isSystem());
    }

    // mode worker : rien ne survit d'une requête à la suivante
    public function testResetForgetsTheTenant(): void
    {
        $context = new StackTenantContext(1);
        $context->reset();

        self::assertNull($context->getTenantId());
        self::assertFalse($context->isSystem());
    }

    /**
     * @param \ArrayObject<int, string> $log
     */
    private static function listener(\ArrayObject $log, bool $refuse = false): TenantSwitchListener
    {
        return new class($log, $refuse) implements TenantSwitchListener {
            /**
             * @param \ArrayObject<int, string> $log
             */
            public function __construct(private readonly \ArrayObject $log, private readonly bool $refuse)
            {
            }

            public function switching(TenantScope $from, TenantScope $to, bool $leaving): void
            {
                $this->log[] = $from->describe() . ' -> ' . $to->describe() . ($leaving ? ' (leaving)' : '');
                if ($this->refuse) {
                    throw new \LogicException('refused');
                }
            }
        };
    }

    // RG-08.8 : chaque changement effectif est annoncé, à l'entrée et à la sortie ; reset() non
    public function testListenersAreToldOfEachEffectiveSwitch(): void
    {
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $context = new StackTenantContext(1);
        $context->addSwitchListener($listener = self::listener($log));

        $context->runAs(1, static fn (): null => null);
        $context->runAs(2, static fn (): mixed => $context->runAsSystem(static fn (): null => null));
        $context->setTenantId(3);
        $context->runAs(4, static fn () => $context->setTenantId(5));
        $context->reset();

        self::assertSame([
            'tenant 1 -> tenant 2',
            'tenant 2 -> system mode',
            'system mode -> tenant 2 (leaving)',
            'tenant 2 -> tenant 1 (leaving)',
            'tenant 1 -> tenant 3',
            'tenant 3 -> tenant 4',
            // sous un runAs(), setTenantId() ne change que la base, retrouvée en sortie
            'tenant 4 -> tenant 5 (leaving)',
        ], $log->getArrayCopy());
    }

    // un listener qui refuse l'entrée l'empêche : le callback ne s'exécute pas
    public function testListenerCanRefuseToEnter(): void
    {
        $context = new StackTenantContext(1);
        $context->addSwitchListener($listener = self::listener(new \ArrayObject(), refuse: true));
        $ran = false;

        try {
            $context->runAs(2, static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('no exception');
        } catch (\LogicException) {
        }

        self::assertFalse($ran);
        self::assertSame(1, $context->getTenantId());
    }

    // l'exception du callback l'emporte sur celle d'un listener à la sortie
    public function testCallbackExceptionWinsOnLeave(): void
    {
        $context = new StackTenantContext(1);
        $listener = new class implements TenantSwitchListener {
            public function switching(TenantScope $from, TenantScope $to, bool $leaving): void
            {
                if ($leaving) {
                    throw new \LogicException('listener');
                }
            }
        };
        $context->addSwitchListener($listener);

        try {
            $context->runAs(2, static function () use ($context): int {
                if (2 === $context->getTenantId()) {
                    throw new \RuntimeException('callback');
                }

                return 0;
            });
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertSame('callback', $e->getMessage());
        }
        self::assertSame(1, $context->getTenantId());

        $this->expectExceptionMessage('listener');
        $context->runAs(2, static fn (): null => null);
    }

    // une référence faible : un listener jeté n'est plus prévenu, ni retenu
    public function testListenersAreWeakReferences(): void
    {
        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();
        $context = new StackTenantContext(1);
        $context->addSwitchListener(self::listener($log));

        $context->runAs(2, static fn (): null => null);

        self::assertCount(0, $log);
    }
}
