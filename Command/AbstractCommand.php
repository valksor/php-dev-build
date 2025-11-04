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

namespace ValksorDev\Build\Command;

use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;
use Valksor\Bundle\Command\AbstractCommand as BundleAbstractCommand;
use Valksor\Component\Sse\Helper;
use ValksorDev\Build\Provider\IoAwareInterface;
use ValksorDev\Build\Provider\ProviderRegistry;

use function method_exists;
use function usleep;

/**
 * Abstract base class for Valksor build system commands.
 *
 * This class provides common patterns, utilities, and configuration handling
 * for all build commands in the Valksor development system. It establishes
 * consistent behavior across build commands and reduces code duplication.
 *
 * Command Patterns Provided:
 * - Watch mode handling with automatic cleanup
 * - Non-interactive mode support for CI/automation
 * - Initialization phase execution (binary downloads, dependency setup)
 * - SSE server startup and management
 * - Minification control based on environment
 * - Provider registry integration for service coordination
 *
 * Key Features:
 * - Extends BundleAbstractCommand for core Valksor functionality
 * - Provides utility methods for common command operations
 * - Handles service lifecycle management in watch mode
 * - Integrates with the provider registry for service discovery
 * - Supports both development and production environments
 *
 * Design Principles:
 * - Consistent command interface across all build tools
 * - Graceful degradation in different environments
 * - Proper resource cleanup and shutdown handling
 * - Flexible configuration through command options
 */
abstract class AbstractCommand extends BundleAbstractCommand
{
    use Helper;

    /**
     * Initialize the abstract command with required dependencies.
     *
     * The constructor receives core dependencies needed by all build commands:
     * - Parameter bag for accessing application configuration and build settings
     * - Provider registry for service discovery and coordination
     *
     * The provider registry is marked as protected readonly to allow extending
     * commands to access service providers while preventing modification.
     *
     * @param ParameterBagInterface $parameterBag     Application configuration and build parameters
     * @param ProviderRegistry      $providerRegistry Registry of available service providers
     */
    public function __construct(
        ParameterBagInterface $parameterBag,
        protected readonly ProviderRegistry $providerRegistry,
    ) {
        parent::__construct($parameterBag);
    }

    /**
     * Handle watch mode setup and cleanup for long-running services.
     *
     * This method implements the watch mode pattern used throughout the build system.
     * It provides automatic cleanup and resource management for services that run
     * indefinitely in watch mode, ensuring proper shutdown and preventing resource leaks.
     *
     * Watch Mode Pattern:
     * - Returns a cleanup function that can be called during shutdown
     * - Handles service lifecycle management (start/stop)
     * - Manages PID file cleanup for background processes
     * - Provides no-op cleanup for non-watch mode execution
     *
     * Usage Pattern:
     * ```php
     * $cleanup = $this->handleWatchMode($service, $input, 'tailwind');
     * try {
     *     // Run service logic here
     * } finally {
     *     $cleanup(); // Always cleanup, even on exceptions
     * }
     * ```
     *
     * This approach ensures that long-running services (file watchers, compilers)
     * are properly cleaned up when the command terminates or receives signals.
     *
     * @param object         $service     The service instance to manage
     * @param InputInterface $input       Command input to determine watch mode
     * @param string         $serviceName Service name for logging/debugging
     *
     * @return callable Cleanup function that should be called when the command finishes
     */
    protected function handleWatchMode(
        object $service,
        InputInterface $input,
        string $serviceName,
    ): callable {
        if (!$this->isWatchMode($input)) {
            // Return a no-op cleanup function for non-watch mode
            // This allows consistent cleanup calling without conditional logic
            return function (): void {
                // No cleanup needed for one-time execution
            };
        }

        // For watch mode, create a cleanup function that handles graceful shutdown
        // This ensures resources are properly released when the command exits
        return static function () use ($service): void {
            // Stop the service if it supports lifecycle management
            // Most long-running services implement a stop() method for graceful shutdown
            if (method_exists($service, 'stop')) {
                $service->stop();
            }

            // Clean up PID files if the service supports process tracking
            // This prevents stale PID files from interfering with future runs
            if (method_exists($service, 'removePidFile')) {
                $service->removePidFile();
            }
        };
    }

    protected function isNonInteractive(
        InputInterface $input,
    ): bool {
        return (bool) $input->getOption('non-interactive');
    }

    protected function isWatchMode(
        InputInterface $input,
    ): bool {
        return (bool) $input->getOption('watch');
    }

    /**
     * Run init phase - always runs first for all commands.
     */
    protected function runInit(
        SymfonyStyle $io,
    ): void {
        $servicesConfig = $this->parameterBag->get('valksor.build.services');
        $initProviders = $this->providerRegistry->getProvidersByFlag($servicesConfig, 'init');

        if (empty($initProviders)) {
            return;
        }

        $io->section('Running initialization tasks...');

        // Binaries always run first
        if (isset($initProviders['binaries'])) {
            $io->text('Ensuring binaries are available...');
            $this->runProvider('binaries', $initProviders['binaries'], []);
            unset($initProviders['binaries']);
        }

        // Run remaining init providers
        foreach ($initProviders as $name => $provider) {
            $config = $servicesConfig[$name] ?? [];
            $options = $config['options'] ?? [];
            $this->runProvider($name, $provider, $options);
        }

        $io->success('Initialization completed');
    }

    /**
     * Run a single provider with error handling.
     */
    protected function runProvider(
        string $name,
        $provider,
        array $options,
    ): void {
        try {
            $provider->init($options);
        } catch (Exception $e) {
            // In development, warn but continue; in production, fail
            if ($this->isProductionEnvironment()) {
                throw new RuntimeException("Provider '$name' failed: " . $e->getMessage(), 0, $e);
            }
            // Warning - continue but this could be problematic in non-interactive mode
        }
    }

    /**
     * Get SSE command for integration.
     */
    protected function runSseCommand(): int
    {
        $process = new Process(['php', 'bin/console', 'valksor:sse']);

        // Start SSE server in background (non-blocking)
        $process->start();

        // Give SSE server more time to start and bind to port
        $maxWaitTime = 3; // 3 seconds max wait time
        $waitInterval = 250000; // 250ms intervals
        $elapsedTime = 0;

        while ($elapsedTime < $maxWaitTime) {
            usleep($waitInterval);
            $elapsedTime += ($waitInterval / 1000000);

            // Check if process is still running and hasn't failed
            if (!$process->isRunning()) {
                // Process stopped - check if it was successful
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            }

            // After 1 second, check if we can verify the server is actually stable
            if ($elapsedTime >= 1.0) {
                // The SSE server should be stable by now, proceed
                break;
            }
        }

        // Final check - is the process still running successfully?
        return $process->isRunning() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Set SymfonyStyle on provider objects that support it.
     */
    protected function setProviderIo(
        object $provider,
        SymfonyStyle $io,
    ): void {
        if ($provider instanceof IoAwareInterface) {
            $provider->setIo($io);
        }
    }

    /**
     * Determine whether build output should be minified based on configuration.
     *
     * This method implements the minification decision pattern used across build commands.
     * It provides a hierarchical approach to determining minification settings:
     *
     * Decision Priority (highest to lowest):
     * 1. Command-line --no-minify flag (explicitly disable minification)
     * 2. Command-line --minify flag (explicitly enable minification)
     * 3. Environment-based setting (production = minify, development = unminified)
     *
     * This approach allows developers to override default behavior while maintaining
     * sensible defaults for different environments.
     *
     * Use Cases:
     * - Development: Debuggable, unminified output for easier troubleshooting
     * - Production: Optimized, minified output for better performance
     * - CI/CD: Explicit control via command-line flags
     * - Testing: Disable minification for better assertion debugging
     *
     * @param InputInterface $input Command input containing minification options
     *
     * @return bool True if output should be minified, false otherwise
     */
    protected function shouldMinify(
        InputInterface $input,
    ): bool {
        // Priority 1: Explicit --no-minify flag overrides all other settings
        // This allows developers to force unminified output even in production
        if ($input->hasOption('no-minify') && $input->getOption('no-minify')) {
            return false;
        }

        // Priority 2: Explicit --minify flag enables minification
        // Useful for testing production builds in development
        if ($input->hasOption('minify') && $input->getOption('minify')) {
            return true;
        }

        // Priority 3: Default behavior based on environment
        // Production environments typically want minified assets for performance
        return $this->isProductionEnvironment();
    }

    protected function shouldShowRealTimeOutput(
        InputInterface $input,
    ): bool {
        return !$this->isNonInteractive($input);
    }
}
