<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Config;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Services configuration value object.
 *
 * Typed configuration for development services to run in valksor:watch.
 */
readonly class ServicesConfig
{
    public array $services;

    public function __construct(
        #[Autowire(service: 'parameter_bag')]
        private readonly ParameterBagInterface $parameterBag,
    ) {
        $servicesData = $parameterBag->get('valksor.build.services', []);
        $this->services = self::createServicesFromArray($servicesData);
        $this->validate();
    }

    private static function createServicesFromArray(array $config): array
    {
        $services = [];

        foreach ($config as $key => $value) {
            if (is_int($key) && is_string($value)) {
                // Simple format: ['tailwind', 'importmap']
                $services[$value] = new ServiceConfig(enabled: true);
            } elseif (is_string($key) && is_array($value)) {
                // Detailed format: ['tailwind' => ['enabled' => true, 'watch_dirs' => [...]]]
                $services[$key] = new ServiceConfig(
                    enabled: $value['enabled'] ?? true,
                    watchDirs: $value['watch_dirs'] ?? null,
                    options: $value['options'] ?? [],
                );
            } elseif (is_string($key) && is_bool($value)) {
                // Boolean format: ['tailwind' => true]
                $services[$key] = new ServiceConfig(enabled: $value);
            } else {
                // Invalid format - skip or let validation handle it
                continue;
            }
        }

        return $services;
    }

    /**
     * Get enabled service names.
     *
     * @return array<string>
     */
    public function getEnabledServiceNames(): array
    {
        return array_keys($this->getEnabledServices());
    }

    /**
     * Get all enabled service configurations.
     *
     * @return array<string,ServiceConfig>
     */
    public function getEnabledServices(): array
    {
        return array_filter($this->services, static fn (ServiceConfig $service) => $service->enabled);
    }

    /**
     * Get configuration for a specific service.
     */
    public function getService(
        string $serviceName,
    ): ?ServiceConfig {
        return $this->services[$serviceName] ?? null;
    }

    /**
     * Get all service names.
     *
     * @return array<string>
     */
    public function getServiceNames(): array
    {
        return array_keys($this->services);
    }

    /**
     * Check if any services are configured.
     */
    public function hasServices(): bool
    {
        return !empty($this->services);
    }

    /**
     * Check if a service is enabled.
     */
    public function isServiceEnabled(
        string $serviceName,
    ): bool {
        $service = $this->getService($serviceName);

        return $service?->enabled ?? false;
    }

    /**
     * Create from raw configuration array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(
        array $config,
    ): self {
        $services = [];

        foreach ($config as $key => $value) {
            if (is_int($key) && is_string($value)) {
                // Simple format: ['tailwind', 'importmap']
                $services[$value] = new ServiceConfig(enabled: true);
            } elseif (is_string($key) && is_array($value)) {
                // Detailed format: ['tailwind' => ['enabled' => true, 'watch_dirs' => [...]]]
                $services[$key] = new ServiceConfig(
                    enabled: $value['enabled'] ?? true,
                    watchDirs: $value['watch_dirs'] ?? null,
                    options: $value['options'] ?? [],
                );
            } elseif (is_string($key) && is_bool($value)) {
                // Boolean format: ['tailwind' => true]
                $services[$key] = new ServiceConfig(enabled: $value);
            } else {
                // Invalid format - skip or let validation handle it
                continue;
            }
        }

        return new self(services: $services);
    }

    /**
     * Get default services configuration.
     */
    public static function getDefault(): self
    {
        return new self([
            'tailwind' => new ServiceConfig(enabled: true),
            'importmap' => new ServiceConfig(enabled: true),
            'sse' => new ServiceConfig(enabled: true),
        ]);
    }

    private function validate(): void
    {
        foreach ($this->services as $name => $service) {
            if (!is_string($name) || empty($name)) {
                throw new InvalidArgumentException('Service names must be non-empty strings');
            }
        }
    }
}

/**
 * Individual service configuration.
 */
readonly class ServiceConfig
{
    /**
     * @param bool                $enabled   Whether the service is enabled
     * @param array<string>|null  $watchDirs Override global watch directories for this service
     * @param array<string,mixed> $options   Service-specific options
     */
    public function __construct(
        public bool $enabled = true,
        public ?array $watchDirs = null,
        public array $options = [],
    ) {
    }

    /**
     * Get a specific option value.
     */
    public function getOption(
        string $key,
        mixed $default = null,
    ): mixed {
        return $this->options[$key] ?? $default;
    }

    /**
     * Get watch directories for this service.
     */
    public function getWatchDirs(
        array $globalWatchDirs = [],
    ): array {
        return $this->watchDirs ?? $globalWatchDirs;
    }

    /**
     * Check if the service has custom watch directories.
     */
    public function hasCustomWatchDirs(): bool
    {
        return null !== $this->watchDirs;
    }

    /**
     * Check if an option exists.
     */
    public function hasOption(
        string $key,
    ): bool {
        return array_key_exists($key, $this->options);
    }
}
