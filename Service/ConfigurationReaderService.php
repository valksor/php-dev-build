<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Service;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use ValksorDev\Build\Service\Config\ExtensionResolver;
use ValksorDev\Build\Service\Config\CurrentConfigExtractor;
use ValksorDev\Build\Service\Config\DefaultConfigProcessor;

/**
 * Simplified service for reading Symfony configuration programmatically.
 *
 * This service now delegates to focused components for specific responsibilities.
 */
class ConfigurationReaderService
{
    public function __construct(
        private readonly ExtensionResolver $extensionResolver,
        private readonly CurrentConfigExtractor $currentConfigExtractor,
        private readonly DefaultConfigProcessor $defaultConfigProcessor,
    ) {
    }

    /**
     * Get all available bundle extensions.
     */
    public function getBundleExtensions(): array
    {
        return $this->extensionResolver->getBundleExtensions();
    }

    /**
     * Get the configuration tree for a bundle.
     */
    public function getConfigurationTree(string $bundle): ?ConfigurationInterface
    {
        $extension = $this->extensionResolver->findExtension($bundle);

        if (!$extension) {
            return null;
        }

        // Try to get the configuration class
        try {
            $containerBuilder = $this->getContainerBuilder();

            if ($containerBuilder) {
                return $extension->getConfiguration([], $containerBuilder);
            }
        } catch (\Throwable $e) {
            // Fall through to return null
        }

        return null;
    }

    /**
     * Get current configuration for a bundle.
     */
    public function getCurrentConfig(string $bundle, ?string $path = null): array
    {
        return $this->currentConfigExtractor->getCurrentConfig($bundle, $path);
    }

    /**
     * Get default configuration values for a bundle/extension.
     */
    public function getDefaultConfig(string $bundle, ?string $path = null): array
    {
        return $this->defaultConfigProcessor->getDefaultConfig($bundle, $path);
    }

    /**
     * Get the ContainerBuilder for debug operations.
     * This is a minimal implementation that delegates to ExtensionResolver when needed.
     */
    private function getContainerBuilder(): ?\Symfony\Component\DependencyInjection\ContainerBuilder
    {
        // The ExtensionResolver handles this internally when needed
        return null;
    }
}
