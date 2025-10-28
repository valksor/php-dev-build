<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Service\Config;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\KernelInterface;

use function levenshtein;

/**
 * Service for finding and managing Symfony bundle extensions.
 */
final class ExtensionResolver
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * Find the extension for a bundle name using Symfony's exact approach.
     */
    public function findExtension(string $name): ?ExtensionInterface
    {
        $bundles = $this->kernel->getBundles();
        $minScore = \INF;

        // Check if kernel itself is an extension
        if ($this->kernel instanceof ExtensionInterface &&
            ($this->kernel instanceof \Symfony\Component\Config\Definition\ConfigurationInterface ||
             $this->kernel instanceof \Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface)) {
            if ($name === $this->kernel->getAlias()) {
                return $this->kernel;
            }

            if ($this->kernel->getAlias()) {
                $distance = levenshtein($name, $this->kernel->getAlias());

                if ($distance < $minScore) {
                    $guess = $this->kernel->getAlias();
                    $minScore = $distance;
                }
            }
        }

        // Check bundles
        foreach ($bundles as $bundle) {
            if ($name === $bundle->getName()) {
                if (!$bundle->getContainerExtension()) {
                    throw new \LogicException(sprintf('Bundle "%s" does not have a container extension.', $name));
                }

                return $bundle->getContainerExtension();
            }

            $distance = levenshtein($name, $bundle->getName());

            if ($distance < $minScore) {
                $guess = $bundle->getName();
                $minScore = $distance;
            }
        }

        return null;
    }

    /**
     * Get all available bundle extensions.
     */
    public function getBundleExtensions(): array
    {
        $containerBuilder = $this->getContainerBuilder();

        if (!$containerBuilder) {
            return [];
        }

        $extensions = [];

        foreach ($containerBuilder->getExtensions() as $name => $extension) {
            $extensions[$name] = [
                'class' => $extension::class,
                'extension' => $extension,
            ];
        }

        return $extensions;
    }

    /**
     * Get the ContainerBuilder for debug operations by rebuilding it.
     */
    private function getContainerBuilder(): ?\Symfony\Component\DependencyInjection\ContainerBuilder
    {
        try {
            $buildContainer = \Closure::bind(function () {
                $this->initializeBundles();

                return $this->buildContainer();
            }, $this->kernel, \Symfony\Component\HttpKernel\Kernel::class);

            return $buildContainer();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
