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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;

use function array_key_exists;
use function is_array;
use function is_string;
use function preg_match;

/**
 * Service for processing default configuration values from Symfony config trees.
 */
final class DefaultConfigProcessor
{
    public function __construct(
        private readonly ExtensionResolver $extensionResolver,
    ) {
    }

    /**
     * Get default configuration values for a bundle/extension.
     */
    public function getDefaultConfig(string $bundle, ?string $path = null): array
    {
        $extension = $this->extensionResolver->findExtension($bundle);

        if (!$extension) {
            throw new \RuntimeException("Extension not found for bundle: {$bundle}");
        }

        $configuration = $this->getConfiguration($extension);

        if (!$configuration instanceof ConfigurationInterface) {
            throw new \RuntimeException('Extension does not have a valid Configuration class');
        }

        // Get the configuration tree and process it properly
        $treeBuilder = $configuration->getConfigTreeBuilder();
        $configTree = $treeBuilder->buildTree();

        // Process the configuration tree to get defaults
        $processor = new Processor();

        try {
            $defaultConfig = $processor->process($configTree, []);

            // Post-process to handle prototype arrays that return null
            $defaultConfig = $this->handlePrototypeArraysInDefaults($defaultConfig, $configTree);
        } catch (\Exception $e) {
            // If processing fails, try to get normalized defaults from the tree
            $defaultConfig = $this->extractDefaultsFromConfigTree($configTree);
        }

        // Navigate to specific path if provided
        if (null !== $path) {
            return $this->navigateToPath($defaultConfig, $path);
        }

        return $defaultConfig;
    }

    /**
     * Get the configuration object for an extension.
     */
    private function getConfiguration(ExtensionInterface $extension): ?ConfigurationInterface
    {
        try {
            $containerBuilder = $this->getContainerBuilder();

            if ($containerBuilder) {
                $configuration = $extension->getConfiguration([], $containerBuilder);
            } else {
                // Fallback for when ContainerBuilder is not available
                $configuration = null;
            }
        } catch (\Throwable $e) {
            // Handle cases where extension doesn't support getConfiguration properly
            $configuration = null;
        }

        return $configuration;
    }

    /**
     * Extract default values from a configuration tree when processing fails.
     */
    private function extractDefaultsFromConfigTree(
        \Symfony\Component\Config\Definition\NodeInterface $configTree,
    ): array {
        $defaults = [];

        if ($configTree instanceof \Symfony\Component\Config\Definition\ArrayNode) {
            $children = $configTree->getChildren();

            foreach ($children as $name => $child) {
                if ($child instanceof \Symfony\Component\Config\Definition\ScalarNode) {
                    $defaults[$name] = $child->getDefaultValue();
                } elseif ($child instanceof \Symfony\Component\Config\Definition\PrototypedArrayNode) {
                    // Skip prototype arrays from defaults - they are user-defined structures
                    continue;
                } elseif ($child instanceof \Symfony\Component\Config\Definition\ArrayNode) {
                    $defaults[$name] = $this->extractDefaultsFromConfigTree($child);
                }
            }
        }

        return $defaults;
    }

    /**
     * Handle prototype arrays in default configuration by removing or replacing null values.
     */
    private function handlePrototypeArraysInDefaults(
        array $config,
        \Symfony\Component\Config\Definition\NodeInterface $configTree,
    ): array {
        foreach ($config as $key => $value) {
            if (null === $value) {
                // Check if this is a prototype array in the tree
                if ($configTree instanceof \Symfony\Component\Config\Definition\ArrayNode
                    && isset($configTree->getChildren()[$key])
                    && $configTree->getChildren()[$key] instanceof \Symfony\Component\Config\Definition\PrototypedArrayNode) {
                    // Remove prototype arrays from defaults since they are user-defined
                    unset($config[$key]);
                }
            } elseif (is_array($value)) {
                // Recursively handle nested arrays
                if ($configTree instanceof \Symfony\Component\Config\Definition\ArrayNode
                    && isset($configTree->getChildren()[$key])) {
                    $config[$key] = $this->handlePrototypeArraysInDefaults($value, $configTree->getChildren()[$key]);
                }
            }
        }

        return $config;
    }

    /**
     * Navigate to a specific path in a configuration array.
     */
    private function navigateToPath(array $config, string $path): array
    {
        $keys = explode('.', $path);
        $current = $config;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return [];
            }
            $current = $current[$key];
        }

        return is_array($current) ? $current : [];
    }

    /**
     * Get the ContainerBuilder for debug operations.
     */
    private function getContainerBuilder(): ?ContainerBuilder
    {
        // This would need to be implemented or injected if needed
        // For now, we'll return null as it's a fallback
        return null;
    }
}
