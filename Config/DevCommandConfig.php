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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Dev command configuration value object.
 *
 * Typed configuration for valksor:dev command (lightweight development mode).
 */
readonly class DevCommandConfig
{
    public ServicesConfig $services;
    public bool $skipBinaries;
    public bool $skipInitialization;
    public bool $skipAssetCleanup;

    public function __construct(
        #[Autowire(service: 'parameter_bag')]
        private readonly ParameterBagInterface $parameterBag,
    ) {
        $servicesData = $parameterBag->get('valksor.build.dev_command.services', []);
        $this->services = new ServicesConfig($this->createServicesFromArray($servicesData));
        $this->skipBinaries = $parameterBag->get('valksor.build.dev_command.skip_binaries', true);
        $this->skipInitialization = $parameterBag->get('valksor.build.dev_command.skip_initialization', true);
        $this->skipAssetCleanup = $parameterBag->get('valksor.build.dev_command.skip_asset_cleanup', true);
    }

    private function createServicesFromArray(array $config): array
    {
        $services = [];

        foreach ($config as $name => $value) {
            if (is_int($name) && is_string($value)) {
                // Simple format: ['tailwind', 'importmap']
                $services[$value] = new ServiceConfig(enabled: true);
            } elseif (is_string($name) && is_array($value)) {
                // Detailed format: ['tailwind' => ['enabled' => true, 'watch_dirs' => [...]]]
                $services[$name] = new ServiceConfig(
                    enabled: $value['enabled'] ?? true,
                    watchDirs: $value['watch_dirs'] ?? null,
                    options: $value['options'] ?? [],
                );
            } elseif (is_string($name) && is_bool($value)) {
                // Boolean format: ['tailwind' => true]
                $services[$name] = new ServiceConfig(enabled: $value);
            } else {
                // Invalid format - skip or let validation handle it
                continue;
            }
        }

        return $services;
    }

    /**
     * Check if any optimizations are enabled.
     */
    public function hasOptimizations(): bool
    {
        return !$this->skipBinaries || !$this->skipInitialization || !$this->skipAssetCleanup;
    }

    /**
     * Check if asset cleanup should be skipped.
     */
    public function shouldSkipAssetCleanup(): bool
    {
        return $this->skipAssetCleanup;
    }

    /**
     * Check if binary checks should be skipped.
     */
    public function shouldSkipBinaries(): bool
    {
        return $this->skipBinaries;
    }

    /**
     * Check if initialization tasks should be skipped.
     */
    public function shouldSkipInitialization(): bool
    {
        return $this->skipInitialization;
    }

    /**
     * Create from raw configuration array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(
        array $config,
    ): self {
        $servicesConfig = ServicesConfig::fromArray($config['services'] ?? []);

        return new self(
            services: $servicesConfig,
            skipBinaries: (bool) ($config['skip_binaries'] ?? true),
            skipInitialization: (bool) ($config['skip_initialization'] ?? true),
            skipAssetCleanup: (bool) ($config['skip_asset_cleanup'] ?? true),
        );
    }

    /**
     * Get default dev command configuration.
     */
    public static function getDefault(): self
    {
        return new self(
            services: ServicesConfig::getDefault(),
            skipBinaries: true,
            skipInitialization: true,
            skipAssetCleanup: true,
        );
    }
}
