<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Prism\Orm\Tenancy\StackTenantContext;

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
}
