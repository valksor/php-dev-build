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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use ValksorDev\Build\Util\ConsoleCommandBuilder;

use function array_filter;
use function array_slice;
use function count;
use function function_exists;
use function pcntl_async_signals;
use function pcntl_signal;
use function sprintf;
use function time;
use function ucfirst;
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
    private const FAILURE_WINDOW_SECONDS = 30;

    /**
     * Configuration for restart logic.
     */
    private const MAX_FAILURES_IN_WINDOW = 5;

    /**
     * Maximum number of lines to keep in output buffers per service.
     * Prevents memory exhaustion from long-running services.
     */
    private const int MAX_OUTPUT_LINES = 100;
    private const SUCCESS_RESET_SECONDS = 3600; // 1 hour

    /**
     * Error output buffers for capturing service stderr output.
     * Maps service names to arrays of recent error lines with timestamps.
     *
     * @var array<string,array<string>>
     */
    private array $errorBuffers = [];

    /**
     * Track failure timestamps for restart logic.
     * Maps process names to arrays of failure timestamps.
     *
     * @var array<string,array<int>>
     */
    private array $failureHistory = [];

    /**
     * Output buffers for capturing service stdout output.
     * Maps service names to arrays of recent output lines with timestamps.
     *
     * @var array<string,array<string>>
     */
    private array $outputBuffers = [];

    /**
     * Store original command arguments for restart functionality.
     * Maps process names to their command arguments.
     *
     * @var array<string,array<string>>
     */
    private array $processArgs = [];

    /**
     * Mapping of service names to process identifiers.
     * Used for logging and process identification.
     *
     * @var array<string,string>
     */
    private array $processNames = [];

    /**
     * Track parent-child relationships between processes.
     * Maps child process names to parent process names.
     *
     * @var array<string,string|null>
     */
    private array $processParents = [];

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
        private readonly ?ConsoleCommandBuilder $commandBuilder = null,
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

        // Initialize output buffers for this service
        $this->outputBuffers[$name] = [];
        $this->errorBuffers[$name] = [];

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
     * Clear failure history for a process after successful run.
     */
    public function clearFailureHistory(
        string $processName,
    ): void {
        unset($this->failureHistory[$processName]);
        $this->io?->text(sprintf('[CLEAN] Cleared failure history for %s', $processName));
    }

    /**
     * Clear output buffers for a service.
     *
     * @param string $serviceName The service name
     */
    public function clearOutputBuffer(
        string $serviceName,
    ): void {
        $this->outputBuffers[$serviceName] = [];
        $this->errorBuffers[$serviceName] = [];

        $this->io?->text(sprintf('[CLEANUP] Cleared output buffer for %s', $serviceName));
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

        // First, capture any new output from running processes
        $this->captureProcessOutput();

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

            // Display recent output for this service
            $this->displayServiceOutput($name);
        }

        $this->io->newLine();
    }

    /**
     * Execute a single process with support for interactive and non-interactive modes.
     *
     * This method handles different execution scenarios:
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
    public function executeProcess(
        array $arguments,
        bool $isInteractive,
        string $serviceName = 'Service',
    ): int {
        // The first argument is the command name (e.g., 'valksor:tailwind')
        $commandName = $arguments[0];
        $commandArgs = array_slice($arguments, 1);

        $process = $this->commandBuilder?->build($commandName, [])
            ?? new Process(['php', 'bin/console', ...$arguments]);

        if ($isInteractive) {
            // Interactive mode - start process and let it run in background
            // Used for watch services that should continue running
            try {
                // Enable output capture for interactive processes
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
            } catch (ProcessTimedOutException) {
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
     * Get the command arguments for a process.
     *
     * @return array<string>
     */
    public function getProcessArgs(
        string $processName,
    ): array {
        return $this->processArgs[$processName] ?? [];
    }

    /**
     * Get the parent process for a given process.
     */
    public function getProcessParent(
        string $processName,
    ): ?string {
        return $this->processParents[$processName] ?? null;
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
     * Get recent output for a service.
     *
     * @param string $serviceName The service name
     * @param int    $limit       Maximum number of lines to return
     *
     * @return array<string>
     */
    public function getRecentOutput(
        string $serviceName,
        int $limit = 10,
    ): array {
        $output = $this->outputBuffers[$serviceName] ?? [];
        $errors = $this->errorBuffers[$serviceName] ?? [];

        // Combine and get most recent lines
        $allOutput = array_merge($errors, $output);

        return array_slice($allOutput, -$limit);
    }

    /**
     * Get the root parent process by traversing up the hierarchy.
     */
    public function getRootParent(
        string $processName,
    ): ?string {
        $current = $processName;
        $visited = [];

        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            $parent = $this->processParents[$current] ?? null;

            if (null === $parent) {
                return $current; // This is the root
            }
            $current = $parent;
        }

        return null; // Circular reference detected
    }

    /**
     * Handle process failure by restarting the appropriate command.
     *
     * @param string $failedProcessName The name of the failed process
     *
     * @return int Command exit code
     */
    public function handleProcessFailure(
        string $failedProcessName,
    ): int {
        $this->recordFailure($failedProcessName);

        // Determine which process to restart (parent or self)
        $processToRestart = $this->getRootParent($failedProcessName) ?? $failedProcessName;

        $this->io?->warning(sprintf('[FAILURE] Process %s failed, restarting %s...', $failedProcessName, $processToRestart));

        return $this->restartProcess($processToRestart);
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
     * @return void This method terminates the process
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
        foreach ($this->processes as $process) {
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
     * Record a failure for a process with timestamp.
     */
    public function recordFailure(
        string $processName,
    ): void {
        $this->failureHistory[$processName][] = time();
        $this->io?->warning(sprintf('[FAILURE] Recorded failure for %s (total: %d)', $processName, count($this->failureHistory[$processName])));
    }

    /**
     * Remove a stopped process from tracking.
     */
    public function removeProcess(
        string $name,
    ): void {
        if (isset($this->processes[$name])) {
            unset(
                $this->processes[$name],
                $this->processNames[$name],
                $this->outputBuffers[$name],
                $this->errorBuffers[$name],
            );

            $this->io?->text(sprintf('[CLEANUP] Removed %s from tracking', $name));
        }
    }

    /**
     * Restart a process using its stored arguments.
     *
     * @param string $processName The process to restart
     *
     * @return int Command exit code
     */
    public function restartProcess(
        string $processName,
    ): int {
        $args = $this->getProcessArgs($processName);

        if (empty($args)) {
            $this->io?->error(sprintf('[RESTART] No arguments stored for %s, cannot restart', $processName));

            return Command::FAILURE;
        }

        if (!$this->shouldRestartProcess($processName)) {
            $this->terminateAll();

            exit(Command::FAILURE);
        }

        // Add exponential backoff delay based on failure count
        $failureCount = count($this->failureHistory[$processName] ?? []);
        $backoffDelay = min(5 * 2 ** ($failureCount - 1), 30); // Max 30 seconds

        if ($failureCount > 0) {
            $this->io?->text(sprintf('[RESTART] Waiting %d seconds before restart (failure #%d)', $backoffDelay, $failureCount));
            usleep($backoffDelay * 1000000); // Convert to microseconds
        }

        $this->io?->warning(sprintf('[RESTART] Restarting process %s...', $processName));

        // Only terminate the failed process, not all processes
        $this->terminateProcess($processName);

        // Start the new process
        $process = new Process($args);
        $process->start();

        // Give it time to initialize
        usleep(500000);

        if ($process->isRunning()) {
            $this->io?->success(sprintf('[RESTART] Successfully restarted %s', $processName));

            return Command::SUCCESS;
        }
        $this->recordFailure($processName);
        $this->io?->error(sprintf('[RESTART] Failed to restart %s (exit code: %d)', $processName, $process->getExitCode()));

        // If restart failed, try again or give up
        return $this->restartProcess($processName);
    }

    /**
     * Store the original command arguments for a process.
     *
     * @param string        $processName The process name
     * @param array<string> $args        The command arguments
     */
    public function setProcessArgs(
        string $processName,
        array $args,
    ): void {
        $this->processArgs[$processName] = $args;
        $this->io?->text(sprintf('[ARGS] Stored arguments for %s: %s', $processName, implode(' ', $args)));
    }

    /**
     * Set the parent process for a given process.
     *
     * @param string      $processName The child process name
     * @param string|null $parentName  The parent process name, or null if it's a root process
     */
    public function setProcessParent(
        string $processName,
        ?string $parentName = null,
    ): void {
        $this->processParents[$processName] = $parentName;
        $this->io?->text(sprintf('[PARENT] Set %s as parent of %s', $parentName ?? 'root', $processName));
    }

    /**
     * Check if a process should be restarted based on failure history.
     */
    public function shouldRestartProcess(
        string $processName,
    ): bool {
        $now = time();
        $failures = $this->failureHistory[$processName] ?? [];

        // Remove old failures outside the window
        $recentFailures = array_filter($failures, fn ($timestamp) => $now - $timestamp <= self::FAILURE_WINDOW_SECONDS);

        // Update failure history with only recent failures
        $this->failureHistory[$processName] = $recentFailures;

        // Check if we have too many recent failures
        if (count($recentFailures) >= self::MAX_FAILURES_IN_WINDOW) {
            $this->io?->error(sprintf('[GIVE_UP] Too many failures for %s (%d in %d seconds), giving up', $processName, count($recentFailures), self::FAILURE_WINDOW_SECONDS));

            return false;
        }

        return true;
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
     * Terminate a specific process gracefully.
     *
     * @param string $processName The name of the process to terminate
     */
    public function terminateProcess(
        string $processName,
    ): void {
        if (!isset($this->processes[$processName])) {
            $this->io?->warning(sprintf('[STOPPING] Process %s not found in registry', $processName));

            return;
        }

        $process = $this->processes[$processName];

        if (!$process->isRunning()) {
            $this->io?->text(sprintf('[STOPPING] Process %s is not running', $processName));

            return;
        }

        $this->io?->text(sprintf('[STOPPING] Terminating %s process (PID: %d)', $processName, $process->getPid()));

        // Phase 1: Graceful termination using SIGTERM (signal 15)
        $process->stop(2); // Send SIGTERM with 2-second timeout for graceful shutdown

        // Phase 2: Force kill if still running using SIGKILL (signal 9)
        if ($process->isRunning()) {
            $this->io?->warning(sprintf('[FORCE-KILL] Forcefully killing %s process', $processName));
            $process->signal(9); // SIGKILL - cannot be caught or ignored
        } else {
            $this->io?->success(sprintf('[STOPPING] Successfully terminated %s process', $processName));
        }
    }

    /**
     * Add output line to the appropriate buffer with rotation.
     *
     * @param string $serviceName The service name
     * @param string $output      The output line(s)
     * @param bool   $isError     Whether this is error output
     */
    private function addOutputToBuffer(
        string $serviceName,
        string $output,
        bool $isError,
    ): void {
        if ($isError) {
            $buffer = &$this->errorBuffers[$serviceName];
        } else {
            $buffer = &$this->outputBuffers[$serviceName];
        }

        // Split output into lines and add with timestamps
        $lines = explode("\n", trim($output));
        $timestamp = date('H:i:s');

        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }

            $buffer[] = sprintf('[%s] %s', $timestamp, $line);

            // Rotate buffer if it exceeds maximum size
            if (count($buffer) > self::MAX_OUTPUT_LINES) {
                array_shift($buffer); // Remove oldest line
            }
        }
    }

    /**
     * Capture and buffer output from all running processes.
     * This method reads any new output from processes and stores it in buffers.
     */
    private function captureProcessOutput(): void
    {
        foreach ($this->processes as $name => $process) {
            if (!$process->isRunning()) {
                continue;
            }

            // Capture stdout output
            $output = $process->getIncrementalOutput();

            if ('' !== $output) {
                $this->addOutputToBuffer($name, $output, false);
            }

            // Capture stderr output
            $errorOutput = $process->getIncrementalErrorOutput();

            if ('' !== $errorOutput) {
                $this->addOutputToBuffer($name, $errorOutput, true);
            }
        }
    }

    /**
     * Display recent output for a specific service.
     *
     * @param string $serviceName The service name
     */
    private function displayServiceOutput(
        string $serviceName,
    ): void {
        $output = $this->outputBuffers[$serviceName] ?? [];
        $errors = $this->errorBuffers[$serviceName] ?? [];

        // Display error output first (more important)
        foreach ($errors as $errorLine) {
            $this->io?->text(sprintf('  <error>[%s] %s</error>', strtoupper($serviceName), $errorLine));
        }

        // Display regular output
        foreach ($output as $outputLine) {
            $this->io?->text(sprintf('  [%s] %s', strtoupper($serviceName), $outputLine));
        }
    }
}
