<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Context;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use ValksorDev\Build\Config\BuildStepConfig;

/**
 * Unified context for both watch services and build steps.
 *
 * Replaces ServiceContext and BuildStepContext to eliminate duplication.
 * Provides access to project information, configuration, and utilities.
 */
final class ExecutionContext
{
    /**
     * @param string                        $projectRoot       Absolute path to project root
     * @param SymfonyStyle                  $io                Console I/O for output
     * @param callable|null                 $executeSubCommand Callable to execute sub-commands (build mode)
     * @param BuildStepConfig|null          $stepConfig        Build step configuration (build mode only)
     * @param string|null                   $devAppBin         Path to dev app console binary (watch mode only)
     * @param array<int, string>|null       $availableApps    List of available app IDs (watch mode only)
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly SymfonyStyle $io,
        public readonly ?BuildStepConfig $stepConfig = null,
        public readonly ?string $devAppBin = null,
        public readonly ?array $availableApps = null,
        public readonly mixed $executeSubCommand = null,
    ) {
    }

    /**
     * Create a process that runs a console command (watch mode).
     *
     * @param array<int, string> $arguments Command arguments (e.g., ['messenger:consume', 'sentry'])
     *
     * @return Process Configured but not started process
     */
    public function createConsoleProcess(array $arguments): Process
    {
        $command = [$this->getConsolePath(), ...$arguments];

        return new Process($command);
    }

    /**
     * Create a process that runs a dev app console command (watch mode).
     *
     * @param array<int, string> $arguments Command arguments
     *
     * @return Process Configured but not started process
     */
    public function createDevAppProcess(array $arguments): Process
    {
        if (null === $this->devAppBin) {
            throw new \RuntimeException('Dev app binary not available in this context');
        }

        $command = [$this->devAppBin, ...$arguments];

        return new Process($command);
    }

    /**
     * Execute a sub-command (build mode).
     */
    public function executeSubCommand(
        string $command,
        array $args = [],
    ): mixed {
        if (null === $this->executeSubCommand) {
            throw new \RuntimeException('Command execution not available in this context');
        }

        $callable = $this->executeSubCommand;

        return $callable($command, $args);
    }

    /**
     * Get the console binary path.
     */
    public function getConsolePath(): string
    {
        return $this->projectRoot . '/bin/console';
    }

    /**
     * Get a specific option from build step configuration (build mode).
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        if (null === $this->stepConfig) {
            throw new \RuntimeException('Step configuration not available in this context');
        }

        return $this->stepConfig->getOption($key, $default);
    }

    /**
     * Check if an option exists in build step configuration (build mode).
     */
    public function hasOption(string $key): bool
    {
        if (null === $this->stepConfig) {
            throw new \RuntimeException('Step configuration not available in this context');
        }

        return $this->stepConfig->hasOption($key);
    }

    /**
     * Get available apps (watch mode).
     *
     * @return array<int, string>
     */
    public function getAvailableApps(): array
    {
        if (null === $this->availableApps) {
            throw new \RuntimeException('Available apps not available in this context');
        }

        return $this->availableApps;
    }

    /**
     * Check if this context supports watch mode.
     */
    public function supportsWatchMode(): bool
    {
        return null !== $this->devAppBin && null !== $this->availableApps;
    }

    /**
     * Check if this context supports build mode.
     */
    public function supportsBuildMode(): bool
    {
        return null !== $this->executeSubCommand && null !== $this->stepConfig;
    }

    /**
     * Create a watch-mode context.
     */
    public static function forWatch(
        string $projectRoot,
        string $devAppBin,
        array $availableApps,
        SymfonyStyle $io,
    ): self {
        return new self(
            projectRoot: $projectRoot,
            io: $io,
            devAppBin: $devAppBin,
            availableApps: $availableApps,
        );
    }

    /**
     * Create a build-mode context.
     */
    public static function forBuild(
        string $projectRoot,
        SymfonyStyle $io,
        BuildStepConfig $stepConfig,
        callable $executeSubCommand,
    ): self {
        return new self(
            projectRoot: $projectRoot,
            io: $io,
            executeSubCommand: $executeSubCommand,
            stepConfig: $stepConfig,
        );
    }
}
