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

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Watcher\PathFilter;

/**
 * Factory for creating configuration value objects from parameters.
 */
final class ConfigurationFactory
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    /**
     * Create ProjectStructureConfig from parameters.
     */
    public function createProjectStructureConfig(): ProjectStructureConfig
    {
        return new ProjectStructureConfig(
            appsDir: $this->parameterBag->get('valksor.project.apps_dir', '/apps'),
            sharedDir: $this->parameterBag->get('valksor.project.infrastructure_dir', '/infrastructure'),
            sharedIdentifier: $this->parameterBag->get('valksor.project.infrastructure_dir', '/infrastructure'),
            excludePatterns: $this->parameterBag->get('valksor.project.exclude_patterns', []),
            excludeFiles: $this->parameterBag->get('valksor.project.exclude_files', []),
            templatesDir: $this->parameterBag->get('valksor.project.templates_dir', '/templates'),
            publicDir: $this->parameterBag->get('valksor.project.public_dir', '/public'),
            configDir: $this->parameterBag->get('valksor.project.config_dir', '/config'),
        );
    }

    /**
     * Create SseConfig from parameters.
     */
    public function createSseConfig(): SseConfig
    {
        return new SseConfig(
            bind: $this->parameterBag->get('valksor.sse.bind', '0.0.0.0'),
            port: (int) $this->parameterBag->get('valksor.sse.port', 8001),
            path: $this->parameterBag->get('valksor.sse.path', '/sse'),
            domain: $this->parameterBag->get('valksor.sse.domain', 'localhost'),
        );
    }

    /**
     * Create HotReloadConfig from parameters.
     */
    public function createHotReloadConfig(): HotReloadConfig
    {
        return new HotReloadConfig(
            watchDirs: $this->parameterBag->get('valksor.project.hot_reload.watch_dirs', []),
        );
    }

    /**
     * Create InitializationConfig from parameters.
     */
    public function createInitializationConfig(): InitializationConfig
    {
        $iconsConfig = $this->parameterBag->get('valksor.build.initialization.icons', []);

        return new InitializationConfig(
            icons: IconsConfig::fromArray($iconsConfig),
        );
    }

    /**
     * Create BinariesConfig from parameters.
     */
    public function createBinariesConfig(): BinariesConfig
    {
        return new BinariesConfig(
            binaries: $this->parameterBag->get('valksor.build.binaries', []),
        );
    }

    /**
     * Create ServicesConfig from parameters.
     */
    public function createServicesConfig(): ServicesConfig
    {
        $servicesData = $this->parameterBag->get('valksor.build.services', []);
        $services = [];

        foreach ($servicesData as $name => $config) {
            $services[$name] = new ServiceConfig(
                enabled: $config['enabled'] ?? false,
                options: $config['options'] ?? [],
            );
        }

        return new ServicesConfig($services);
    }

    /**
     * Create ProdBuildConfig from parameters.
     */
    public function createProdBuildConfig(): ProdBuildConfig
    {
        // Try to get steps from configuration, fallback to empty array
        try {
            $stepsData = $this->parameterBag->get('valksor.build.prod_build.steps');
        } catch (\Exception $e) {
            $stepsData = [];
        }

        // If no steps configured, use defaults
        if (empty($stepsData)) {
            return ProdBuildConfig::getDefault();
        }

        $steps = [];
        foreach ($stepsData as $name => $config) {
            $steps[$name] = new BuildStepConfig(
                enabled: $config['enabled'] ?? false,
                options: $config['options'] ?? [],
            );
        }

        return new ProdBuildConfig($steps);
    }

    /**
     * Create DevCommandConfig from parameters.
     */
    public function createDevCommandConfig(): DevCommandConfig
    {
        $servicesData = $this->parameterBag->get('valksor.build.dev_command.services', []);
        $services = [];

        foreach ($servicesData as $name => $config) {
            $services[$name] = new ServiceConfig(
                enabled: $config['enabled'] ?? false,
                options: $config['options'] ?? [],
            );
        }

        return new DevCommandConfig(
            services: new ServicesConfig($services),
            skipBinaries: $this->parameterBag->get('valksor.build.dev_command.skip_binaries', false),
            skipInitialization: $this->parameterBag->get('valksor.build.dev_command.skip_initialization', false),
            skipAssetCleanup: $this->parameterBag->get('valksor.build.dev_command.skip_asset_cleanup', false),
        );
    }

    /**
     * Create PathFilter from project structure configuration.
     */
    public function createPathFilter(): PathFilter
    {
        $projectConfig = $this->createProjectStructureConfig();

        return new PathFilter(
            directories: $projectConfig->excludePatterns,
            ignoredGlobs: [], // Default empty for globs
            filenames: $projectConfig->excludeFiles,
            extensions: ['log', 'tmp'], // Common extensions to ignore
        );
    }
}
