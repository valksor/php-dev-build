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
use ValksorDev\Build\Provider\ProviderRegistry;

use function count;
use function function_exists;
use function in_array;
use function sleep;
use function sprintf;

use const SIGINT;
use const SIGTERM;

final class DevService
{
    private ?SymfonyStyle $io = null;
    private bool $isInteractive = true;
    private ?ProcessManager $processManager = null;
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

    public function start(): int
    {
        if ($this->io) {
            $this->io->title('Development Mode');
        }

        // Initialize process manager for tracking background services
        $this->processManager = new ProcessManager($this->io);

        // Register signal handlers for graceful shutdown
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
                if ($this->io) {
                    $this->io->warning('[TERMINATE] Received termination signal - shutting down gracefully...');
                }
                $this->processManager->terminateAll();
                $this->running = false;

                exit(0);
            });
        }

        // Run init phase first
        $this->runInit();

        // Get services configuration from ParameterBag
        $servicesConfig = $this->parameterBag->get('valksor.build.services', []);

        // Get lightweight dev services (hot_reload and any dev=true services)
        $devProviders = $this->providerRegistry->getProvidersByFlag($servicesConfig, 'dev');

        // Filter for lightweight services (SSE + hot_reload)
        $lightweightProviders = [];

        foreach ($devProviders as $name => $provider) {
            $config = $servicesConfig[$name] ?? [];
            $providerClass = $config['provider'] ?? $name;

            // Only include lightweight providers (SSE and hot_reload)
            if (in_array($providerClass, ['hot_reload'], true)) {
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
            if ($this->io) {
                $this->io->error(sprintf('Missing providers for: %s', implode(', ', $missingProviders)));
            }

            return Command::FAILURE;
        }

        // Start SSE first before any providers (required for hot reload communication)
        if ($this->isInteractive && $this->io) {
            $this->io->text('Starting SSE server...');
        }
        $sseResult = $this->runSseCommand();

        if (Command::SUCCESS !== $sseResult) {
            if ($this->io) {
                $this->io->error('✗ SSE server failed to start');
            }

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
                    if ($this->io) {
                        $this->io->error(sprintf('Failed to start %s service', $name));
                    }

                    return Command::FAILURE;
                }

                // Track the process in our manager
                $this->processManager->addProcess($name, $process);
                $runningServices[] = $name;

                if ($this->isInteractive && $this->io) {
                    $this->io->success(sprintf('[READY] %s service started successfully', ucfirst($name)));
                }
            } catch (Exception $e) {
                if ($this->io) {
                    $this->io->error(sprintf('Service "%s" failed: %s', $name, $e->getMessage()));
                }

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

        if ($this->processManager) {
            $this->processManager->terminateAll();
        }
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
        $servicesConfig = $this->parameterBag->get('valksor.build.services', []);
        $initProviders = $this->providerRegistry->getProvidersByFlag($servicesConfig, 'init');

        if (empty($initProviders)) {
            return;
        }

        if ($this->io) {
            $this->io->section('Running initialization tasks...');
        }

        // Binaries always run first
        if (isset($initProviders['binaries'])) {
            if ($this->io) {
                $this->io->text('Ensuring binaries are available...');
            }
            $this->runProvider('binaries', $initProviders['binaries'], []);
            unset($initProviders['binaries']);
        }

        // Run remaining init providers
        foreach ($initProviders as $name => $provider) {
            $config = $servicesConfig[$name] ?? [];
            $options = $config['options'] ?? [];
            $this->runProvider($name, $provider, $options);
        }

        if ($this->io) {
            $this->io->success('Initialization completed');
        }
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
                throw new RuntimeException("Provider '{$name}' failed: " . $e->getMessage(), 0, $e);
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
        if ($provider instanceof \ValksorDev\Build\Provider\IoAwareInterface) {
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
            if ($this->io) {
                $this->io->error(sprintf('Process %s failed to start (exit code: %d)', $name, $process->getExitCode()));
            }

            return null;
        }

        return $process;
    }
}
