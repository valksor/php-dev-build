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

namespace ValksorDev\Build\Binary;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function array_keys;
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
     * @param string $name The binary name (e.g., 'tailwindcss', '@valksor/valksor@next')
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

        // Direct match first
        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        // For @valksor/valksor packages, return the base provider
        if (str_starts_with($name, '@valksor/valksor')) {
            return $this->providers['@valksor/valksor'];
        }

        throw new RuntimeException(sprintf('No binary provider registered for: %s', $name));
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
     * @param string $name The binary name (e.g., 'tailwindcss', '@valksor/valksor@next')
     *
     * @return bool True if provider exists
     */
    public function has(
        string $name,
    ): bool {
        // Direct match first
        if (isset($this->providers[$name])) {
            return true;
        }

        // For @valksor/valksor packages, check for base provider
        if (str_starts_with($name, '@valksor/valksor')) {
            return isset($this->providers['@valksor/valksor']);
        }

        return false;
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
