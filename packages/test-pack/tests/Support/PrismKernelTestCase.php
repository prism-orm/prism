<?php

declare(strict_types=1);

namespace Prism\TestPack\Tests\Support;

use Prism\Bundle\Tests\Support\TestKernel;
use Prism\TestPack\QueryCountAssertions;
use Prism\TestPack\ResetDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Ce qu'une application écrirait : un KernelTestCase avec ResetDatabase. Deux EntityManagers,
 * « default » (Library) et « billing », sur la base de test, ou en mémoire.
 */
abstract class PrismKernelTestCase extends KernelTestCase
{
    use QueryCountAssertions;
    use ResetDatabase;

    /** @var list<TestKernel> */
    private static array $kernels = [];

    abstract protected static function url(): string;

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $dir = \dirname(__DIR__) . '/Fixtures/Entity';
        $kernel = new TestKernel([
            'connections' => ['default' => ['url' => static::url()], 'billing' => ['url' => static::url()]],
            'entity_managers' => [
                'default' => ['entity_dirs' => [$dir . '/Library']],
                'billing' => ['entity_dirs' => [$dir . '/Billing']],
            ],
        ]);
        self::$kernels[] = $kernel;

        return $kernel;
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        foreach (self::$kernels as $kernel) {
            $kernel->removeDirectory();
        }
        self::$kernels = [];
    }
}
