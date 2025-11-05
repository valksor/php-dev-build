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
 * Registry for dev service providers with dependency resolution.
 *
 * This class serves as the central service provider registry for the Valksor build system.
 * It handles:
 * - Automatic service provider discovery via Symfony's autowiring iterator
 * - Service provider lookup by name with validation
 * - Configuration-based provider filtering by service flags (init, dev, prod)
 * - Dependency resolution and service ordering
 * - Provider validation against configuration
 *
 * The registry enables extensible service architecture where new build services
 * can be added by implementing ProviderInterface without modifying core build logic.
 */
final class ProviderRegistry
{
    /**
     * Registry of all available service providers indexed by service name.
     *
     * This array stores all discovered service providers where the key is the
     * provider's service name (e.g., 'tailwind', 'importmap', 'hot_reload')
     * and the value is the ProviderInterface implementation.
     *
     * @var array<string, ProviderInterface>
     */
    private array $providers = [];

    /**
     * Initialize the provider registry with auto-discovered service providers.
     *
     * This constructor uses Symfony's dependency injection container to automatically
     * discover all services tagged with 'valksor.service_provider'. The autowiring
     * iterator provides all registered providers without requiring manual registration.
     *
     * The autowiring mechanism allows new service providers to be added to the system
     * simply by tagging them in the service configuration, making the build system
     * highly extensible without code modifications.
     *
     * @param iterable<ProviderInterface> $providers Auto-discovered collection of all
     *                                               services tagged with 'valksor.service_provider'
     */
    public function __construct(
        #[AutowireIterator(
            'valksor.service_provider',
        )]
        iterable $providers,
    ) {
        // Register each discovered provider using the service name as the key
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
     * Get providers filtered by service configuration flags with dependency resolution.
     *
     * This method filters available service providers based on their configuration
     * flags (init, dev, prod) and resolves dependencies to ensure proper execution order.
     * It handles:
     * - Service enablement checking
     * - Flag-based filtering (init/dev/prod modes)
     * - Custom provider name resolution
     * - Dependency-aware sorting
     *
     * Example configuration structure:
     * [
     *     'tailwind' => [
     *         'enabled' => true,
     *         'flags' => ['dev' => true, 'prod' => true],
     *         'provider' => 'tailwind' // optional, defaults to service name
     *     ]
     * ]
     *
     * @param array  $servicesConfig Services configuration from valksor.php config file
     * @param string $flag           The flag to filter by ('init', 'dev', or 'prod')
     *
     * @return array<string, ProviderInterface> Filtered and sorted providers [service_name => provider]
     */
    public function getProvidersByFlag(
        array $servicesConfig,
        string $flag,
    ): array {
        $filteredProviders = [];

        // Process each service configuration entry
        foreach ($servicesConfig as $name => $config) {
            // Skip disabled services - allows conditional service enabling
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            // Skip services that don't have the requested flag enabled
            // This enables mode-specific service execution (e.g., dev-only services)
            if (!($config['flags'][$flag] ?? false)) {
                continue;
            }

            // Resolve the actual provider name (supports custom provider mappings)
            // This allows one provider to serve multiple service configurations
            $providerName = $config['provider'] ?? $name;

            // Only include providers that actually exist in the registry
            if ($this->has($providerName)) {
                $filteredProviders[$name] = $this->get($providerName);
            }
        }

        // Sort providers by execution order and resolve dependencies
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
     * Sort providers by service order and resolve dependencies using topological sorting.
     *
     * This method implements a two-phase sorting algorithm:
     * 1. Primary sorting by configured service order (numerical priority)
     * 2. Dependency resolution to ensure dependent services run after their dependencies
     *
     * The dependency resolution uses a topological sort approach:
     * - Iteratively adds providers whose dependencies are already satisfied
     * - Detects circular dependencies and missing dependencies
     * - Falls back to original order for problematic configurations
     *
     * This ensures that services like 'hot_reload' (which depends on CSS/JS outputs)
     * run after 'tailwind' and 'importmap' have completed their builds.
     *
     * @param array<string, ProviderInterface> $providers Providers to sort and resolve
     *
     * @return array<string, ProviderInterface> Sorted providers with dependencies resolved
     */
    private function sortProvidersByOrder(
        array $providers,
    ): array {
        // Phase 1: Sort by service order (numerical priority)
        // Lower numbers typically indicate services that should run first
        uasort($providers, static fn (ProviderInterface $a, ProviderInterface $b) => $a->getServiceOrder() - $b->getServiceOrder());

        // Phase 2: Topological sort for dependency resolution
        $sorted = [];
        $remaining = $providers;

        // Continue until all providers are sorted or no progress can be made
        while (!empty($remaining)) {
            $addedInIteration = false;

            // Find providers whose dependencies are already satisfied
            foreach ($remaining as $name => $provider) {
                $dependencies = $provider->getDependencies();
                $allDependenciesMet = true;

                // Check if all declared dependencies are already in the sorted list
                foreach ($dependencies as $dependency) {
                    if (!isset($sorted[$dependency])) {
                        $allDependenciesMet = false;

                        break; // Missing dependency - this provider must wait
                    }
                }

                // If all dependencies are satisfied, add this provider to the sorted list
                if ($allDependenciesMet) {
                    $sorted[$name] = $provider;
                    unset($remaining[$name]);
                    $addedInIteration = true;
                }
            }

            // Circular dependency detection: if no providers were added in this iteration
            // but some remain, we have either circular dependencies or missing dependencies
            if (!$addedInIteration) {
                // Add remaining providers in their current order to prevent infinite loops
                // This allows the system to continue running even with misconfigured dependencies
                $sorted += $remaining;

                break;
            }
        }

        return $sorted;
    }
}
