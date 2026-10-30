<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prism\Bundle\DependencyInjection\Compiler\FilterPass;
use Prism\Bundle\DependencyInjection\Compiler\ListenerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FilterPassTest extends TestCase
{
    public function testNamedFilterIsNotAlsoRegisteredByItsUnnamedAutoconfigurationTag(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(ListenerPass::PARAMETER, ['default']);
        $container->register('prism.filters.default');
        $container->register('App\\Filter\\ArchivedCustomerFilter')
            ->addTag(FilterPass::TAG)
            ->addTag(FilterPass::TAG, ['name' => 'archived_customers']);
        $container->register('App\\Filter\\OtherFilter')->addTag(FilterPass::TAG);

        new FilterPass()->process($container);

        $calls = $container->getDefinition('prism.filters.default')->getMethodCalls();
        self::assertCount(2, $calls);

        $names = [];
        foreach ($calls as $call) {
            self::assertIsArray($call);
            self::assertSame('add', $call[0]);
            self::assertIsArray($call[1]);
            self::assertIsString($call[1][0]);
            $names[] = $call[1][0];
        }
        self::assertSame(['archived_customers', 'App\\Filter\\OtherFilter'], $names);
    }
}
