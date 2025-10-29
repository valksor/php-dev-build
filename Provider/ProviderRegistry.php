<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Davis Zalitis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Provider;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function sprintf;

/**
 * Registry for dev service providers.
 *
 * Holds all available dev service providers and provides access by name.
 * Makes it easy to add new dev services without modifying core code.
 */
final class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<ProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(
            'valksor.service_provider',
        )]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /**
     * Get a provider by service name.
     *
     * @param string $name The service name (e.g., 'tailwind')
     *
     * @return ProviderInterface The registered provider
     *
     * @throws RuntimeException If no provider is registered for this name
     */
    public function get(
        string $name,
    ): ProviderInterface {
        if (!$this->has($name)) {
            throw new RuntimeException(sprintf('No service provider registered for: %s', $name));
        }

        return $this->providers[$name];
    }

    /**
     * Get all registered provider names.
     *
     * @return array<int, string>
     */
    public function getAvailableNames(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Get providers filtered by service configuration flags.
     *
     * @param array  $servicesConfig Services configuration from valksor.php
     * @param string $flag           The flag to filter by (init, dev, prod)
     *
     * @return array<string, ProviderInterface> Filtered providers [name => provider]
     */
    public function getProvidersByFlag(
        array $servicesConfig,
        string $flag,
    ): array {
        $filteredProviders = [];

        foreach ($servicesConfig as $name => $config) {
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            if (!($config['flags'][$flag] ?? false)) {
                continue;
            }

            $providerName = $config['provider'] ?? $name;

            if ($this->has($providerName)) {
                $filteredProviders[$name] = $this->get($providerName);
            }
        }

        return $this->sortProvidersByOrder($filteredProviders);
    }

    /**
     * Check if a provider is registered for the given service name.
     *
     * @param string $name The service name (e.g., 'tailwind')
     *
     * @return bool True if provider exists
     */
    public function has(
        string $name,
    ): bool {
        return isset($this->providers[$name]);
    }

    /**
     * Register a dev service provider.
     *
     * @param ProviderInterface $provider The provider to register
     */
    public function register(
        ProviderInterface $provider,
    ): void {
        $this->providers[$provider->getName()] = $provider;
    }

    /**
     * Validate that all configured providers exist.
     *
     * @param array $servicesConfig Services configuration from valksor.php
     *
     * @return array<string> Array of missing provider names
     */
    public function validateProviders(
        array $servicesConfig,
    ): array {
        $missing = [];

        foreach ($servicesConfig as $name => $config) {
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            $providerName = $config['provider'] ?? $name;

            if (!$this->has($providerName)) {
                $missing[] = $providerName;
            }
        }

        return $missing;
    }

    /**
     * Sort providers by their service order and resolve dependencies.
     *
     * @param array<string, ProviderInterface> $providers Providers to sort
     *
     * @return array<string, ProviderInterface> Sorted providers
     */
    private function sortProvidersByOrder(
        array $providers,
    ): array {
        // Sort by service order first
        uasort($providers, fn (ProviderInterface $a, ProviderInterface $b) => $a->getServiceOrder() - $b->getServiceOrder());

        // Simple dependency resolution - ensure dependencies run first
        $sorted = [];
        $remaining = $providers;

        while (!empty($remaining)) {
            $addedInIteration = false;

            foreach ($remaining as $name => $provider) {
                $dependencies = $provider->getDependencies();
                $allDependenciesMet = true;

                foreach ($dependencies as $dependency) {
                    if (!isset($sorted[$dependency])) {
                        $allDependenciesMet = false;

                        break;
                    }
                }

                if ($allDependenciesMet) {
                    $sorted[$name] = $provider;
                    unset($remaining[$name]);
                    $addedInIteration = true;
                }
            }

            if (!$addedInIteration) {
                // Circular dependency or missing dependency - add remaining as-is
                $sorted += $remaining;

                break;
            }
        }

        return $sorted;
    }
}
