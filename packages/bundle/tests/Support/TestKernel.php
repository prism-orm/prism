<?php

declare(strict_types=1);

namespace Prism\Bundle\Tests\Support;

use Prism\Bundle\PrismBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Une application minimale : FrameworkBundle, PrismBundle et la configuration prism donnée ;
 * cache dans un répertoire temporaire propre à l'instance.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly string $dir;

    /**
     * @param array<mixed>                               $prism
     * @param \Closure(ContainerConfigurator): void|null $services
     */
    public function __construct(private readonly array $prism, string $environment = 'test', bool $debug = true, private readonly ?\Closure $services = null)
    {
        $this->dir = sys_get_temp_dir() . '/prism-bundle-' . bin2hex(random_bytes(4));
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new PrismBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return $this->dir . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->dir . '/log';
    }

    public function removeDirectory(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'prism', 'http_method_override' => false, 'handle_all_throwables' => true, 'php_errors' => ['log' => true]]);
        $container->extension('prism', $this->prism);
        if (null !== $this->services) {
            ($this->services)($container);
        }
    }
}
