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

use function array_filter;
use function array_key_exists;
use function array_keys;
use function is_array;
use function is_string;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Production build configuration value object.
 *
 * Typed configuration for production build steps and settings.
 */
readonly class ProdBuildConfig
{
    /**
     * @param array<string,BuildStepConfig> $steps Build steps configuration
     */
    public function __construct(
        public array $steps,
    ) {
        $this->validate();
    }

    private static function createStepsFromArray(array $config): array
    {
        $steps = [];

        foreach ($config as $stepName => $stepConfig) {
            if (is_array($stepConfig)) {
                $steps[$stepName] = new BuildStepConfig(
                    enabled: $stepConfig['enabled'] ?? true,
                    options: $stepConfig['options'] ?? [],
                );
            }
        }

        return $steps;
    }

    /**
     * Get enabled step names.
     *
     * @return array<string>
     */
    public function getEnabledStepNames(): array
    {
        return array_keys($this->getEnabledSteps());
    }

    /**
     * Get all enabled build steps.
     *
     * @return array<string,BuildStepConfig>
     */
    public function getEnabledSteps(): array
    {
        return array_filter($this->steps, static fn (BuildStepConfig $step) => $step->enabled);
    }

    /**
     * Get configuration for a specific build step.
     */
    public function getStep(
        string $stepName,
    ): ?BuildStepConfig {
        return $this->steps[$stepName] ?? null;
    }

    /**
     * Get all step names.
     *
     * @return array<string>
     */
    public function getStepNames(): array
    {
        return array_keys($this->steps);
    }

    /**
     * Check if any steps are configured.
     */
    public function hasSteps(): bool
    {
        return !empty($this->steps);
    }

    /**
     * Check if a build step is enabled.
     */
    public function isStepEnabled(
        string $stepName,
    ): bool {
        $step = $this->getStep($stepName);

        return $step?->enabled ?? false;
    }

    /**
     * Create from raw configuration array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(
        array $config,
    ): self {
        $steps = [];
        $stepsConfig = $config['steps'] ?? [];

        foreach ($stepsConfig as $stepName => $stepConfig) {
            if (is_array($stepConfig)) {
                $steps[$stepName] = new BuildStepConfig(
                    enabled: $stepConfig['enabled'] ?? true,
                    options: $stepConfig['options'] ?? [],
                );
            }
        }

        return new self(steps: $steps);
    }

    /**
     * Get default production build configuration.
     */
    public static function getDefault(): self
    {
        return new self([
            'binaries' => new BuildStepConfig(enabled: true, options: []),
            'tailwind' => new BuildStepConfig(enabled: true, options: ['minify' => true]),
            'importmap' => new BuildStepConfig(enabled: true, options: ['minify' => true]),
            'icons' => new BuildStepConfig(enabled: true, options: []),
            'symfony_assets' => new BuildStepConfig(enabled: true, options: []),
        ]);
    }

    private function validate(): void
    {
        foreach ($this->steps as $name => $step) {
            if (!is_string($name) || empty($name)) {
                throw new InvalidArgumentException('Step names must be non-empty strings');
            }
        }
    }
}

