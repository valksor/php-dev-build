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
use Symfony\Component\DependencyInjection\Compiler\ValidateEnvPlaceholdersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_key_exists;

/**
 * Service for extracting current configuration values from Symfony.
 */
final class CurrentConfigExtractor
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ExtensionResolver $extensionResolver,
    ) {
    }

    /**
     * Get current configuration for a bundle.
     */
    public function getCurrentConfig(string $bundle, ?string $path = null): array
    {
        $extension = $this->extensionResolver->findExtension($bundle);

        if (!$extension) {
            return [];
        }

        try {
            $container = $this->compileContainer();
            $config = $this->getConfig($extension, $container, true);

            // Navigate to specific path if provided
            if (null !== $path) {
                return $this->navigateToPath($config, $path);
            }

            return $config;
        } catch (\Throwable $e) {
            // Fallback: try the container approach
            $container = $this->kernel->getContainer();

            if ($container && method_exists($container, 'getExtensionConfig')) {
                $extensionAlias = $extension->getAlias();
                $extensionConfig = $container->getExtensionConfig($extensionAlias);

                if (!empty($extensionConfig)) {
                    $config = $extensionConfig[0] ?? [];

                    // Navigate to specific path if provided
                    if (null !== $path) {
                        return $this->navigateToPath($config, $path);
                    }

                    return $config;
                }
            }
        }

        return [];
    }

    /**
     * Compile a container like Symfony's ConfigDebugCommand does.
     */
    private function compileContainer(): ContainerBuilder
    {
        $kernel = clone $this->kernel;
        $kernel->boot();

        $method = new \ReflectionMethod($kernel, 'buildContainer');
        $container = $method->invoke($kernel);
        $container->getCompiler()->compile($container);

        return $container;
    }

    /**
     * Get configuration using Symfony's exact approach from ConfigDebugCommand.
     */
    private function getConfig(
        ExtensionInterface $extension,
        ContainerBuilder $container,
        bool $resolveEnvs = false,
    ): mixed {
        $config = $this->getConfigForExtension($extension, $container);

        // Try to resolve parameters using the runtime container first, then fallback to compiled container
        try {
            $runtimeContainer = $this->kernel->getContainer();
            $resolvedConfig = $runtimeContainer->getParameterBag()->resolveValue($config);
        } catch (\Throwable $e) {
            // Fallback to compiled container parameter bag
            $resolvedConfig = $container->getParameterBag()->resolveValue($config);
        }

        // Only resolve environment placeholders if explicitly requested
        if ($resolveEnvs) {
            return $container->resolveEnvPlaceholders($resolvedConfig, true);
        }

        return $resolvedConfig;
    }

    /**
     * Get configuration for an extension using Symfony's exact approach.
     */
    private function getConfigForExtension(
        ExtensionInterface $extension,
        ?ContainerBuilder $container,
    ): array {
        if (!$container) {
            return [];
        }

        $extensionAlias = $extension->getAlias();

        // Try to get processed config from ValidateEnvPlaceholdersPass
        $extensionConfig = [];

        foreach ($container->getCompilerPassConfig()->getPasses() as $pass) {
            if ($pass instanceof ValidateEnvPlaceholdersPass) {
                $extensionConfig = $pass->getExtensionConfig();
                break;
            }
        }

        if (isset($extensionConfig[$extensionAlias])) {
            return $extensionConfig[$extensionAlias];
        }

        // Fall back to processing the extension config with the Configuration class
        if (!$extension instanceof ConfigurationExtensionInterface && !$extension instanceof ConfigurationInterface) {
            return [];
        }

        $configs = $container->getExtensionConfig($extensionAlias);
        $configuration = $extension instanceof ConfigurationInterface ? $extension : $extension->getConfiguration($configs, $container);

        if (!$configuration instanceof ConfigurationInterface) {
            return [];
        }

        return (new Processor())->processConfiguration($configuration, $configs);
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
}
