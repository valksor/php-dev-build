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

/**
 * Project structure configuration value object.
 *
 * Typed configuration for project directory structure settings.
 */
readonly class ProjectStructureConfig
{
    public string $appsDir;
    public string $sharedDir;
    public string $sharedIdentifier;
    public array $excludePatterns;
    public array $excludeFiles;
    public string $templatesDir;
    public string $publicDir;
    public string $configDir;

    public function __construct(
        #[Autowire(service: 'parameter_bag')]
        private readonly ParameterBagInterface $parameterBag,
    ) {
        $this->appsDir = $parameterBag->get('valksor.project.apps_dir', '/apps');
        $this->sharedDir = $parameterBag->get('valksor.project.infrastructure_dir', '/infrastructure');
        $this->sharedIdentifier = $parameterBag->get('valksor.project.infrastructure_dir', '/infrastructure');
        $this->excludePatterns = ['node_modules', '.git', 'vendor'];
        $this->excludeFiles = ['.DS_Store', '.gitignore'];
        $this->templatesDir = '/templates';
        $this->publicDir = '/public';
        $this->configDir = '/config';

        $this->validate();
    }

    /**
     * Get the full path to the apps directory.
     */
    public function getAppsPath(
        string $projectRoot,
    ): string {
        return $projectRoot . $this->appsDir;
    }

    /**
     * Get the full path to the config directory.
     */
    public function getConfigPath(
        string $projectRoot,
    ): string {
        return $projectRoot . $this->configDir;
    }

    /**
     * Get the full path to the public directory.
     */
    public function getPublicPath(
        string $projectRoot,
    ): string {
        return $projectRoot . $this->publicDir;
    }

    /**
     * Get the full path to the shared directory.
     */
    public function getSharedPath(
        string $projectRoot,
    ): string {
        return $projectRoot . $this->sharedDir;
    }

    /**
     * Get the full path to the templates directory.
     */
    public function getTemplatesPath(
        string $projectRoot,
    ): string {
        return $projectRoot . $this->templatesDir;
    }

    /**
     * Create from raw configuration array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(
        array $config,
    ): self {
        return new self(
            appsDir: $config['apps_dir'] ?? throw new InvalidArgumentException('apps_dir is required'),
            sharedDir: $config['shared_dir'] ?? throw new InvalidArgumentException('shared_dir is required'),
            sharedIdentifier: $config['shared_identifier'] ?? throw new InvalidArgumentException('shared_identifier is required'),
            excludePatterns: $config['exclude_patterns'] ?? throw new InvalidArgumentException('exclude_patterns is required'),
            excludeFiles: $config['exclude_files'] ?? throw new InvalidArgumentException('exclude_files is required'),
            templatesDir: $config['templates_dir'] ?? '/templates',
            publicDir: $config['public_dir'] ?? '/public',
            configDir: $config['config_dir'] ?? '/config',
        );
    }

    private function validate(): void
    {
        if (empty($this->appsDir)) {
            throw new InvalidArgumentException('apps_dir cannot be empty');
        }

        if (empty($this->sharedDir)) {
            throw new InvalidArgumentException('shared_dir cannot be empty');
        }

        if (empty($this->sharedIdentifier)) {
            throw new InvalidArgumentException('shared_identifier cannot be empty');
        }

        if (empty($this->excludePatterns)) {
            throw new InvalidArgumentException('exclude_patterns cannot be empty');
        }

        if (empty($this->excludeFiles)) {
            throw new InvalidArgumentException('exclude_files cannot be empty');
        }
    }
}
