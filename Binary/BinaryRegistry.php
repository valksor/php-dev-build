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

namespace ValksorDev\Build\Binary;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function sprintf;

/**
 * Registry for binary providers.
 *
 * Holds all available binary providers and provides access by name.
 * Makes it easy to add new binaries without modifying core code.
 */
final class BinaryRegistry
{
    /** @var array<string, BinaryInterface> */
    private array $providers = [];

    /**
     * @param iterable<BinaryInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(
            'valksor.binary_provider',
        )]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /**
     * Get a provider by binary name.
     *
     * @param string $name The binary name (e.g., 'tailwindcss')
     *
     * @return BinaryInterface The registered provider
     *
     * @throws RuntimeException If no provider is registered for this name
     */
    public function get(
        string $name,
    ): BinaryInterface {
        if (!$this->has($name)) {
            throw new RuntimeException(sprintf('No binary provider registered for: %s', $name));
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
     * Check if a provider is registered for the given binary name.
     *
     * @param string $name The binary name (e.g., 'tailwindcss')
     *
     * @return bool True if provider exists
     */
    public function has(
        string $name,
    ): bool {
        return isset($this->providers[$name]);
    }

    /**
     * Register a binary provider.
     *
     * @param BinaryInterface $provider The provider to register
     */
    public function register(
        BinaryInterface $provider,
    ): void {
        $this->providers[$provider->getName()] = $provider;
    }
}
