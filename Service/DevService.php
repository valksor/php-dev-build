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

namespace ValksorDev\Build\Service;

use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;
use ValksorDev\Build\Provider\IoAwareInterface;
use ValksorDev\Build\Provider\ProviderRegistry;

use function array_keys;
use function count;
use function date;
use function function_exists;
use function implode;
use function pcntl_async_signals;
use function pcntl_signal;
use function sleep;
use function sprintf;
use function time;
use function trim;
use function ucfirst;
use function usleep;

use const SIGINT;
use const SIGTERM;

/**
 * Lightweight development service for minimal development environment.
 *
 * This service provides a streamlined development experience by running only
 * the essential services needed for hot reload functionality, without the
 * overhead of full build processes (Tailwind compilation, Importmap processing).
 *
 * Lightweight Service Strategy:
 * - Runs only SSE server and hot reload service
 * - Excludes heavy build processes (Tailwind, Importmap) for faster startup
 * - Perfect for quick development sessions or lightweight projects
 * - Provides instant file watching and browser refresh capabilities
 *
 * Key Differences from DevWatchService:
 * - Faster startup time (fewer services to initialize)
 * - Lower resource usage (no compilation processes)
 * - Simplified monitoring (fewer processes to track)
 * - Focused on hot reload functionality only
 * - Assumes pre-compiled assets are already available
 *
 * Signal Handling:
 * - Registers graceful shutdown handlers for SIGINT (Ctrl+C) and SIGTERM
 * - Ensures clean process termination and resource cleanup
 */
final class DevService
{
    /**
     * Symfony console output interface for user interaction and status reporting.
     * Provides rich console output with sections, progress indicators, and formatted text.
     */
    private ?SymfonyStyle $io = null;

    /**
     * Flag indicating whether the service should provide interactive console output.
     * When false, runs silently in the background for automated/CI environments.
     */
    private bool $isInteractive = true;

    /**
     * Process manager for tracking and coordinating lightweight development services.
     * Handles startup, monitoring, and graceful shutdown of SSE and hot reload processes.
     */
    private ?ProcessManager $processManager = null;

    /**
     * Runtime flag indicating the service is active and should continue monitoring.
     * Set to false during shutdown to signal the monitoring loop to exit gracefully.
     */
    private bool $running = false;

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly ProviderRegistry $providerRegistry,
    ) {
    }

    public function getParameterBag(): ParameterBagInterface
    {
        return $this->parameterBag;
    }

    public function getProviderRegistry(): ProviderRegistry
    {
        return $this->providerRegistry;
    }

    public function setInteractive(
        bool $isInteractive,
    ): void {
        $this->isInteractive = $isInteractive;
    }

    public function setIo(
        SymfonyStyle $io,
    ): void {
        $this->io = $io;
    }

    /**
     * Start the lightweight development service.
     *
     * This method implements a streamlined startup sequence that focuses on
     * essential services only, providing faster startup and lower resource usage:
     *
     * Startup Sequence:
     * 1. Process manager initialization and signal handler registration
     * 2. Initialization phase (binary downloads, dependency setup)
     * 3. Provider discovery and lightweight service filtering
     * 4. SSE server startup for hot reload communication
     * 5. Hot reload service startup
     * 6. Continuous monitoring loop
     *
     * Lightweight Filtering Logic:
     * - Discovers all dev-flagged services
     * - Filters to include only 'hot_reload' provider
     * - Excludes heavy build services (Tailwind, Importmap)
     * - Ensures fast startup and minimal resource consumption
     *
     * This approach is ideal for quick development sessions where build
     * processes can be run separately or aren't needed.
     *
     * @return int Command exit code (Command::SUCCESS or Command::FAILURE)
     */
    public function start(): int
    {
        $this->io?->title('Development Mode');

        // Initialize process manager for tracking background services
        $this->processManager = new ProcessManager($this->io);

        // Register signal handlers for graceful shutdown (SIGINT, SIGTERM)
        // This ensures clean process termination when user presses Ctrl+C
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void {
                if ($this->io) {
                    $this->io->newLine();
                    $this->io->warning('[INTERRUPT] Received Ctrl+C - shutting down gracefully...');
                }
                $this->processManager->terminateAll();
                $this->running = false;

                exit(0);
            });
            pcntl_signal(SIGTERM, function (): void {
                $this->io?->warning('[TERMINATE] Received termination signal - shutting down gracefully...');
                $this->processManager->terminateAll();
                $this->running = false;

                exit(0);
            });
        }

        // Run initialization phase (binary downloads, dependency setup)
        // This ensures all required tools and dependencies are available
        $this->runInit();

        // Get services configuration from ParameterBag
        // Contains service definitions, flags, and options for all build services
        $servicesConfig = $this->parameterBag->get('valksor.build.services');

        // Get all dev services (dev=true) with dependency resolution
        $devProviders = $this->providerRegistry->getProvidersByFlag($servicesConfig, 'dev');

        // Filter for lightweight services only (exclude heavy build processes)
        // This is the key difference from DevWatchService - we only want hot reload
        $lightweightProviders = [];

        foreach ($devProviders as $name => $provider) {
            $config = $servicesConfig[$name] ?? [];
            $providerClass = $config['provider'] ?? $name;

            // Only include lightweight providers (hot_reload service)
            // Excludes Tailwind CSS compilation and Importmap processing
            if ('hot_reload' === $providerClass) {
                $lightweightProviders[$name] = $provider;
            }
        }

        if (empty($lightweightProviders)) {
            if ($this->isInteractive && $this->io) {
                $this->io->warning('No lightweight dev services are enabled in configuration.');
            }

            return Command::SUCCESS;
        }

        // Validate all configured providers exist
        $missingProviders = $this->providerRegistry->validateProviders($servicesConfig);

        if (!empty($missingProviders)) {
            $this->io?->error(sprintf('Missing providers for: %s', implode(', ', $missingProviders)));

            return Command::FAILURE;
        }

        // Start SSE first before any providers (required for hot reload communication)
        if ($this->isInteractive && $this->io) {
            $this->io->text('Starting SSE server...');
        }
        $sseResult = $this->runSseCommand();

        if (Command::SUCCESS !== $sseResult) {
            $this->io?->error('✗ SSE server failed to start');

            return Command::FAILURE;
        }

        if ($this->isInteractive && $this->io) {
            $this->io->success('✓ SSE server started and running');
            $this->io->newLine();
            $this->io->text(sprintf('Running %d lightweight dev service(s)...', count($lightweightProviders)));
            $this->io->newLine();
        }

        $runningServices = [];

        foreach ($lightweightProviders as $name => $provider) {
            if ($this->isInteractive && $this->io) {
                $this->io->section(sprintf('Starting %s', ucfirst($name)));
            }

            $config = $servicesConfig[$name] ?? [];
            $options = $config['options'] ?? [];
            // Pass interactive mode to providers
            $options['interactive'] = $this->isInteractive;

            // Set IO on providers that support it
            $this->setProviderIo($provider, $this->io);

            try {
                if ($this->isInteractive && $this->io) {
                    $this->io->text(sprintf('[INITIALIZING] %s service...', ucfirst($name)));
                }

                // Start the provider service and get the process
                $process = $this->startProviderProcess($name, $provider, $options);

                if (null === $process) {
                    $this->io?->error(sprintf('Failed to start %s service', $name));

                    return Command::FAILURE;
                }

                // Track the process in our manager
                $this->processManager->addProcess($name, $process);
                $runningServices[] = $name;

                if ($this->isInteractive && $this->io) {
                    $this->io->success(sprintf('[READY] %s service started successfully', ucfirst($name)));
                }
            } catch (Exception $e) {
                $this->io?->error(sprintf('Service "%s" failed: %s', $name, $e->getMessage()));

                return Command::FAILURE;
            }
        }

        if ($this->isInteractive && $this->io) {
            $this->io->newLine();
            $this->io->success(sprintf('✓ All %d services running successfully!', count($runningServices)));
            $this->io->text('Press Ctrl+C to stop all services');
            $this->io->newLine();

            // Show initial service status
            $this->processManager->displayStatus();
        }

        // Start the monitoring loop - this keeps the service alive
        $this->running = true;

        return $this->monitorServices();
    }

    public function stop(): void
    {
        $this->running = false;

        $this->processManager?->terminateAll();
    }

    public static function getServiceName(): string
    {
        return 'dev';
    }

    /**
     * Monitor running services and keep the service alive.
     */
    private function monitorServices(): int
    {
        if ($this->isInteractive && $this->io) {
            $this->io->text('[MONITOR] Starting service monitoring loop...');
        }

        $checkInterval = 5; // Check every 5 seconds
        $lastStatusTime = 0;
        $statusDisplayInterval = 30; // Show status every 30 seconds

        while ($this->running) {
            // Check if all processes are still running
            if ($this->processManager && !$this->processManager->allProcessesRunning()) {
                $failedProcesses = $this->processManager->getFailedProcesses();

                if ($this->isInteractive && $this->io) {
                    foreach ($failedProcesses as $name => $process) {
                        $this->io->error(sprintf('[FAILED] Service %s has stopped (exit code: %d)', $name, $process->getExitCode()));

                        // Show error output if available
                        $errorOutput = $process->getErrorOutput();

                        if (!empty($errorOutput)) {
                            $this->io->text(sprintf('Error output: %s', trim($errorOutput)));
                        }
                    }

                    $this->io->warning('[MONITOR] Some services have failed. Press Ctrl+C to exit or continue monitoring...');
                }

                // Remove failed processes from tracking
                foreach (array_keys($failedProcesses) as $name) {
                    $this->processManager->removeProcess($name);
                }
            }

            // Periodic status display
            $currentTime = time();

            if ($this->isInteractive && $this->io && ($currentTime - $lastStatusTime) >= $statusDisplayInterval) {
                $this->io->newLine();
                $this->io->text(sprintf(
                    '[STATUS] %s - Monitoring %d active services',
                    date('H:i:s'),
                    $this->processManager ? $this->processManager->count() : 0,
                ));

                if ($this->processManager && $this->processManager->hasProcesses()) {
                    $this->processManager->displayStatus();
                }

                $lastStatusTime = $currentTime;
            }

            // Sleep for the check interval
            sleep($checkInterval);
        }

        return Command::SUCCESS;
    }

    /**
     * Run init phase - always runs first for all commands.
     */
    private function runInit(): void
    {
        $servicesConfig = $this->parameterBag->get('valksor.build.services');
        $initProviders = $this->providerRegistry->getProvidersByFlag($servicesConfig, 'init');

        if (empty($initProviders)) {
            return;
        }

        $this->io?->section('Running initialization tasks...');

        // Binaries always run first
        if (isset($initProviders['binaries'])) {
            $this->io?->text('Ensuring binaries are available...');
            $this->runProvider('binaries', $initProviders['binaries'], []);
            unset($initProviders['binaries']);
        }

        // Run remaining init providers
        foreach ($initProviders as $name => $provider) {
            $config = $servicesConfig[$name] ?? [];
            $options = $config['options'] ?? [];
            $this->runProvider($name, $provider, $options);
        }

        $this->io?->success('Initialization completed');
    }

    /**
     * Run a single provider with error handling.
     */
    private function runProvider(
        string $name,
        object $provider,
        array $options,
    ): void {
        try {
            $provider->init($options);
        } catch (Exception $e) {
            // In development, warn but continue; in production, fail
            if ('prod' === ($_ENV['APP_ENV'] ?? 'dev')) {
                throw new RuntimeException("Provider '$name' failed: " . $e->getMessage(), 0, $e);
            }
            // Warning - continue but this could be problematic in non-interactive mode
            // TODO: Consider passing SymfonyStyle instance for proper warning display
        }
    }

    /**
     * Get SSE command for integration.
     */
    private function runSseCommand(): int
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
    private function setProviderIo(
        object $provider,
        SymfonyStyle $io,
    ): void {
        if ($provider instanceof IoAwareInterface) {
            $provider->setIo($io);
        }
    }

    /**
     * Start a lightweight provider process for background execution.
     *
     * This method creates and starts processes for the limited set of services
     * supported by the lightweight development mode. Unlike DevWatchService,
     * this only supports the hot_reload service since we intentionally exclude
     * heavy build processes.
     *
     * Supported Services in Lightweight Mode:
     * - hot_reload: File watching and browser refresh functionality
     *
     * Excluded Services (handled by DevWatchService):
     * - tailwind: CSS compilation and processing
     * - importmap: JavaScript module processing
     *
     * The process startup includes verification to ensure the service
     * started successfully before adding it to the process manager.
     *
     * @param string $name     Service name (e.g., 'hot_reload')
     * @param object $provider Service provider instance
     * @param array  $options  Service-specific configuration options
     *
     * @return Process|null Process object for tracking, or null if startup failed
     */
    private function startProviderProcess(
        string $name,
        object $provider,
        array $options,
    ): ?Process {
        // Command mapping for lightweight services only
        // Intentionally excludes heavy build services (tailwind, importmap)
        $command = match ($name) {
            'hot_reload' => ['php', 'bin/console', 'valksor:hot-reload'],
            default => null, // Unsupported service in lightweight mode
        };

        if (null === $command) {
            return null;
        }

        // Create and start the process for background execution
        $process = new Process($command);
        $process->start();

        // Allow time for process initialization and startup verification
        // 500ms provides sufficient time for the hot reload service to initialize
        usleep(500000); // 500ms

        // Verify that the process started successfully and is running
        if (!$process->isRunning()) {
            $this->io?->error(sprintf('Process %s failed to start (exit code: %d)', $name, $process->getExitCode()));

            return null;
        }

        return $process;
    }
}
