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
use ValksorDev\Build\Util\ConsoleCommandBuilder;

use function array_keys;
use function count;
use function date;
use function implode;
use function sleep;
use function sprintf;
use function time;
use function trim;
use function ucfirst;
use function usleep;

/**
 * Development watch service orchestrator for the Valksor build system.
 *
 * This service manages the complete development workflow, coordinating multiple
 * build services (Tailwind, Importmap, Hot Reload) in the proper sequence.
 * It handles:
 *
 * Service Orchestration:
 * - Initialization phase (binaries, dependency setup)
 * - SSE server startup for hot reload communication
 * - Multi-service startup with dependency resolution
 * - Background process lifecycle management
 *
 * Monitoring and Status:
 * - Process health monitoring with failure detection
 * - Interactive status display and progress reporting
 * - Graceful shutdown handling with signal management
 * - Error reporting and debugging information
 *
 * Environment Support:
 * - Interactive vs non-interactive execution modes
 * - IO injection for providers that need console access
 * - Configuration-based service discovery and filtering
 * - Cross-platform process management
 */
final class DevWatchService
{
    /**
     * Symfony console output interface for user interaction and status reporting.
     * Enables rich console output with sections, progress indicators, and formatted text.
     */
    private ?SymfonyStyle $io = null;

    /**
     * Flag indicating whether the service should provide interactive console output.
     * When false, runs silently in the background for automated/CI environments.
     */
    private bool $isInteractive = true;

    /**
     * Process manager for tracking and coordinating background build services.
     * Handles startup, monitoring, and graceful shutdown of all child processes.
     */
    private ?ProcessManager $processManager = null;

    /**
     * Runtime flag indicating the service is active and should continue monitoring.
     * Set to false during shutdown to signal the monitoring loop to exit gracefully.
     */
    private bool $running = false;

    /**
     * Initialize the development watch service orchestrator.
     *
     * The service requires access to the application configuration and the provider
     * registry to discover and manage build services. These dependencies are injected
     * via Symfony's dependency injection container.
     *
     * @param ParameterBagInterface $parameterBag     Application configuration and parameters
     * @param ProviderRegistry      $providerRegistry Registry of available service providers
     */
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly ProviderRegistry $providerRegistry,
        private readonly ConsoleCommandBuilder $commandBuilder,
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
     * Start the development watch service orchestrator.
     *
     * This method executes the complete development workflow in phases:
     * 1. Process manager initialization for background service tracking
     * 2. Configuration validation and provider discovery
     * 3. Initialization phase (binary downloads, dependency setup)
     * 4. SSE server startup for hot reload communication
     * 5. Sequential provider startup with dependency resolution
     * 6. Continuous monitoring loop for process health
     *
     * The orchestration ensures proper service startup order and provides
     * comprehensive error handling and status reporting throughout the process.
     *
     * @return int Command exit code (Command::SUCCESS or Command::FAILURE)
     */
    public function start(): int
    {
        $this->io?->title('Development Watch Mode');

        // Initialize process manager for tracking background services
        // Handles process lifecycle, health monitoring, and graceful shutdown
        $this->processManager = new ProcessManager($this->io);

        // Register this watch service as root parent for restart tracking
        $this->processManager->setProcessParent('watch', null); // watch is root
        $this->processManager->setProcessArgs('watch', ['php', 'bin/console', 'valksor:watch']);

        // Get services configuration from ParameterBag
        // Contains service definitions, flags, and options for all build services
        $servicesConfig = $this->parameterBag->get('valksor.build.services');

        // Get all dev services (dev=true) with dependency resolution
        // Returns providers sorted by execution order and dependencies
        $devProviders = $this->providerRegistry->getProvidersByFlag($servicesConfig, 'dev');

        if (empty($devProviders)) {
            if ($this->isInteractive && $this->io) {
                $this->io->warning('No dev services are enabled in configuration.');
            }

            return Command::SUCCESS;
        }

        // Validate all configured providers exist
        $missingProviders = $this->providerRegistry->validateProviders($servicesConfig);

        if (!empty($missingProviders)) {
            $this->io?->error(sprintf('Missing providers for: %s', implode(', ', $missingProviders)));

            return Command::FAILURE;
        }

        // Run init phase first
        $this->runInit();

        // Start SSE first before any providers (required for hot reload communication)
        if ($this->isInteractive && $this->io) {
            $this->io->text('Starting SSE server...');
        }
        $sseResult = $this->runSseCommand();

        if (Command::SUCCESS !== $sseResult) {
            $this->io?->error('✗ SSE server failed to start');

            return Command::FAILURE;
        }

        // Register SSE as child of watch service
        $this->processManager->setProcessParent('sse', 'watch');
        $this->processManager->setProcessArgs('sse', ['php', 'bin/console', 'valksor:sse']);

        if ($this->isInteractive && $this->io) {
            $this->io->success('✓ SSE server started and running');
            $this->io->newLine();
            $this->io->text(sprintf('Starting %d dev service(s)...', count($devProviders)));
            $this->io->newLine();
        }

        $runningServices = [];

        foreach ($devProviders as $name => $provider) {
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

                // Start the provider process and get the process
                $process = $this->startProviderProcess($name, $provider, $options);

                if (null === $process) {
                    $this->io?->error(sprintf('Failed to start %s service', $name));

                    return Command::FAILURE;
                }

                // Track the process in our manager
                $this->processManager->addProcess($name, $process);
                $this->processManager->setProcessParent($name, 'watch'); // Set watch as parent

                // Set appropriate command arguments based on service name
                $commandArgs = match ($name) {
                    'hot_reload' => ['php', 'bin/console', 'valksor:hot-reload'],
                    'tailwind' => ['php', 'bin/console', 'valksor:tailwind', '--watch'],
                    'importmap' => ['php', 'bin/console', 'valksor:importmap', '--watch'],
                    default => ['php', 'bin/console', "valksor:{$name}"],
                };
                $this->processManager->setProcessArgs($name, $commandArgs);

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

    /**
     * Monitor running services and maintain the orchestrator lifecycle.
     *
     * This method implements the main monitoring loop that:
     * - Continuously checks the health of all background processes
     * - Detects and reports service failures with detailed error information
     * - Provides periodic status updates in interactive mode
     * - Manages failed process cleanup and removal from tracking
     * - Maintains the service alive until shutdown signal is received
     *
     * The monitoring uses configurable intervals to balance responsiveness
     * with system resource usage. Failed services are logged but don't
     * immediately terminate the entire development environment.
     *
     * @return int Command exit code (always SUCCESS for monitoring loop)
     */
    private function monitorServices(): int
    {
        if ($this->isInteractive && $this->io) {
            $this->io->text('[MONITOR] Starting service monitoring loop...');
        }

        $checkInterval = 5; // Check every 5 seconds - balances responsiveness with resource usage
        $lastStatusTime = 0;
        $statusDisplayInterval = 30; // Show status every 30 seconds - prevents console spam

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
                }

                // Handle restart for failed processes
                foreach (array_keys($failedProcesses) as $name) {
                    $restartResult = $this->processManager->handleProcessFailure($name);

                    if (Command::SUCCESS === $restartResult) {
                        // Restart was successful, exit and let restarted process take over
                        $this->io?->success('[RESTART] Parent process restarted successfully, exiting current process');

                        return Command::SUCCESS;
                    }
                    // Restart failed or gave up, remove from tracking and continue
                    $this->processManager->removeProcess($name);
                    $this->io?->warning('[RESTART] Restart failed, removing service from tracking');
                }

                if ($this->isInteractive && $this->io) {
                    $this->io->warning('[MONITOR] Some services have failed and could not be restarted. Press Ctrl+C to exit or continue monitoring...');
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
     * Start the SSE (Server-Sent Events) server for hot reload communication.
     *
     * This method launches the SSE server in the background, which is essential
     * for the hot reload service to communicate browser refresh signals.
     * The SSE server must be started before any build services that generate
     * output files (CSS, JS) that might trigger hot reload events.
     *
     * The startup process includes a sophisticated polling mechanism:
     * - Starts the process in non-blocking mode
     * - Polls process status at 250ms intervals for up to 3 seconds
     * - Allows 1 second for the server to stabilize before proceeding
     * - Detects early startup failures and port binding issues
     *
     * This approach handles race conditions where the SSE server needs time
     * to bind to the port and initialize before build services start sending signals.
     *
     * @return int Command exit code (SUCCESS if server started, FAILURE otherwise)
     */
    private function runSseCommand(): int
    {
        $process = $this->commandBuilder->build('valksor:sse');

        // Start SSE server in background (non-blocking mode)
        // This allows the orchestrator to continue with other service startups
        $process->start();

        // Poll-based startup verification to handle race conditions
        // The SSE server needs time to bind to port and initialize
        $maxWaitTime = 3; // 3 seconds max wait time for startup
        $waitInterval = 250000; // 250ms intervals - frequent but not aggressive
        $elapsedTime = 0;

        while ($elapsedTime < $maxWaitTime) {
            usleep($waitInterval);
            $elapsedTime += ($waitInterval / 1000000);

            // Check if process is still running and hasn't failed immediately
            if (!$process->isRunning()) {
                // Process stopped during startup - check if it was successful
                // This catches port conflicts, missing dependencies, etc.
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            }

            // After 1 second, assume the SSE server is stable and ready
            // Most startup failures occur immediately, so 1s is sufficient
            if ($elapsedTime >= 1.0) {
                break; // Server should be stable by now, proceed with other services
            }
        }

        // Final verification - ensure the process is still running
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
     * Start a provider process and return the Process object for tracking.
     */
    private function startProviderProcess(
        string $name,
        object $provider,
        array $options,
    ): ?Process {
        $command = match ($name) {
            'hot_reload' => ['php', 'bin/console', 'valksor:hot-reload'],
            'tailwind' => ['php', 'bin/console', 'valksor:tailwind', '--watch'],
            'importmap' => ['php', 'bin/console', 'valksor:importmap', '--watch'],
            default => null,
        };

        if (null === $command) {
            return null;
        }

        // Create and start the process
        $process = new Process($command);
        $process->start();

        // Give process time to start
        usleep(500000); // 500ms

        // Check if it started successfully
        if (!$process->isRunning()) {
            $this->io?->error(sprintf('Process %s failed to start (exit code: %d)', $name, $process->getExitCode()));

            return null;
        }

        return $process;
    }
}
