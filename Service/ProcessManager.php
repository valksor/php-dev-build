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

use JetBrains\PhpStorm\NoReturn;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

use function count;
use function function_exists;
use function sprintf;
use function usleep;

use const SIGINT;
use const SIGTERM;

/**
 * Process lifecycle manager for the Valksor build system.
 *
 * This class manages background processes used in watch mode, providing:
 * - Process tracking and health monitoring
 * - Graceful shutdown handling with signal management
 * - Process status reporting and failure detection
 * - Interactive vs non-interactive process execution modes
 * - Multi-process coordination and cleanup strategies
 *
 * The manager ensures all build services (Tailwind, Importmap, Hot Reload) can run
 * simultaneously while providing proper cleanup and error handling.
 */
final class ProcessManager
{
    /**
     * Mapping of service names to process identifiers.
     * Used for logging and process identification.
     *
     * @var array<string,string>
     */
    private array $processNames = [];

    /**
     * Registry of managed background processes.
     * Maps service names to Symfony Process instances for lifecycle management.
     *
     * @var array<string,Process>
     */
    private array $processes = [];

    /**
     * Flag indicating whether shutdown has been initiated.
     * Prevents duplicate shutdown operations and signals.
     */
    private bool $shutdown = false;

    public function __construct(
        private readonly ?SymfonyStyle $io = null,
    ) {
        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    /**
     * Add a process to track.
     *
     * @param string  $name    The service name (e.g., 'hot_reload', 'tailwind')
     * @param Process $process The background process
     */
    public function addProcess(
        string $name,
        Process $process,
    ): void {
        $this->processes[$name] = $process;
        $this->processNames[$name] = $name;

        $this->io?->text(sprintf('[TRACKING] Now monitoring %s process (PID: %d)', $name, $process->getPid()));
    }

    /**
     * Check if all processes are still running.
     */
    public function allProcessesRunning(): bool
    {
        foreach ($this->processes as $name => $process) {
            if (!$process->isRunning()) {
                $this->io?->warning(sprintf('[FAILED] Process %s has stopped (exit code: %d)', $name, $process->getExitCode()));

                return false;
            }
        }

        return true;
    }

    /**
     * Get count of tracked processes.
     */
    public function count(): int
    {
        return count($this->processes);
    }

    /**
     * Display current status of all processes.
     */
    public function displayStatus(): void
    {
        if (!$this->io) {
            return;
        }

        $statuses = $this->getProcessStatuses();

        $this->io->section('Service Status');

        foreach ($statuses as $name => $status) {
            $statusIcon = $status['running'] ? '✓' : '✗';
            $statusText = $status['running'] ? 'Running' : 'Stopped';
            $pidInfo = $status['pid'] ? sprintf(' (PID: %d)', $status['pid']) : '';

            $this->io->text(sprintf(
                '%s %s: %s%s',
                $statusIcon,
                ucfirst($name),
                $statusText,
                $pidInfo,
            ));
        }

        $this->io->newLine();
    }

    /**
     * Get failed processes.
     *
     * @return array<string,Process>
     */
    public function getFailedProcesses(): array
    {
        $failed = [];

        foreach ($this->processes as $name => $process) {
            if (!$process->isRunning() && !$process->isSuccessful()) {
                $failed[$name] = $process;
            }
        }

        return $failed;
    }

    /**
     * Get status of all tracked processes.
     *
     * @return array<string,array{running:bool,exit_code:int|null,pid:int|null}>
     */
    public function getProcessStatuses(): array
    {
        $statuses = [];

        foreach ($this->processes as $name => $process) {
            $statuses[$name] = [
                'running' => $process->isRunning(),
                'exit_code' => $process->getExitCode(),
                'pid' => $process->getPid(),
            ];
        }

        return $statuses;
    }

    /**
     * Handle shutdown signals for graceful process termination.
     *
     * This method is called when SIGINT (Ctrl+C) or SIGTERM signals are received.
     * It coordinates the shutdown of all managed processes to ensure clean
     * termination and proper resource cleanup.
     *
     * @param int $signal The signal number (SIGINT or SIGTERM)
     *
     * @return never This method terminates the process
     */
    #[NoReturn]
    public function handleSignal(
        int $signal,
    ): void {
        // Prevent multiple shutdown attempts
        $this->shutdown = true;

        switch ($signal) {
            case SIGINT:
                // User pressed Ctrl+C - provide clear feedback
                if ($this->io) {
                    $this->io->newLine();
                    $this->io->warning('[INTERRUPT] Received Ctrl+C - shutting down gracefully...');
                }

                break;

            case SIGTERM:
                // System or process manager requested termination
                $this->io?->warning('[TERMINATE] Received termination signal - shutting down gracefully...');

                break;
        }

        // Terminate all managed processes before exiting
        $this->terminateAll();

        exit(0);
    }

    /**
     * Check if any process has failed.
     */
    public function hasFailedProcesses(): bool
    {
        foreach ($this->processes as $name => $process) {
            if (!$process->isRunning() && !$process->isSuccessful()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any processes are being tracked.
     */
    public function hasProcesses(): bool
    {
        return !empty($this->processes);
    }

    /**
     * Check if shutdown has been initiated.
     */
    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    /**
     * Remove a stopped process from tracking.
     */
    public function removeProcess(
        string $name,
    ): void {
        if (isset($this->processes[$name])) {
            unset($this->processes[$name], $this->processNames[$name]);

            $this->io?->text(sprintf('[CLEANUP] Removed %s from tracking', $name));
        }
    }

    /**
     * Terminate all tracked processes using a graceful shutdown strategy.
     *
     * This method implements a two-phase termination approach:
     * 1. Graceful termination (SIGTERM) with 2-second timeout
     * 2. Force kill (SIGKILL) for stubborn processes
     *
     * This ensures that processes have time to clean up resources and
     * complete any in-progress operations before being forcefully stopped.
     */
    public function terminateAll(): void
    {
        $this->io?->text('[SHUTDOWN] Terminating all background processes...');

        // Phase 1: Graceful termination using SIGTERM (signal 15)
        // This allows processes to clean up and exit cleanly
        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $this->io?->text(sprintf('[STOPPING] Terminating %s process (PID: %d)', $name, $process->getPid()));
                $process->stop(2); // Send SIGTERM with 2-second timeout for graceful shutdown
            }
        }

        // Phase 2: Force kill any remaining processes using SIGKILL (signal 9)
        // This immediately terminates processes that didn't respond to SIGTERM
        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $this->io?->warning(sprintf('[FORCE-KILL] Forcefully killing %s process', $name));
                $process->signal(9); // SIGKILL - cannot be caught or ignored
            }
        }

        $this->io?->success('[SHUTDOWN] All processes terminated');
    }

    /**
     * Execute a single process with support for interactive and non-interactive modes.
     *
     * This static method handles different execution scenarios:
     * - Interactive mode: Starts process and allows it to run continuously (for watch services)
     * - Non-interactive mode: Runs process to completion and returns exit code
     * - Timeout handling: Manages processes that are expected to run indefinitely
     *
     * @param array  $arguments     Command arguments to pass to the console
     * @param bool   $isInteractive Whether to run in interactive (background) mode
     * @param string $serviceName   Name of the service for logging and user feedback
     *
     * @return int Command exit code (Command::SUCCESS or Command::FAILURE)
     */
    public static function executeProcess(
        array $arguments,
        bool $isInteractive,
        string $serviceName = 'Service',
    ): int {
        $process = new Process(['php', 'bin/console', ...$arguments]);

        if ($isInteractive) {
            // Interactive mode - start process and let it run in background
            // Used for watch services that should continue running
            try {
                $process->start();

                // Give the process time to initialize and start monitoring
                usleep(500000); // 500ms for startup

                if ($process->isRunning()) {
                    // Process started successfully and is running in background
                    // This is the expected behavior for watch services
                    echo sprintf("[RUNNING] %s started and monitoring files for changes\n", $serviceName);

                    return Command::SUCCESS;
                }

                // Process finished quickly (likely an error or quick operation)
                // Check if the execution was successful
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                // Timeout exception occurs when the process runs longer than expected
                // This is normal for watch services - they are designed to run continuously
                if ($process->isRunning()) {
                    // Process is still running despite timeout - let it continue
                    return Command::SUCCESS;
                }

                // Process stopped during or after the timeout
                // Check if it completed successfully before stopping
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            }
        } else {
            // Non-interactive mode - run process to completion without output
            // Used for one-time operations like build commands
            $process->run();

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        }
    }
}
