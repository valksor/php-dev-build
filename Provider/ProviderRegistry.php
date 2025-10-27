<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Provider;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

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
        #[TaggedIterator(
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
}
