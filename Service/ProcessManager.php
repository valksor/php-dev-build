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
 * Manages background processes for the watch command.
 */
final class ProcessManager
{
    /** @var array<string,string> */
    private array $processNames = [];

    /** @var array<string,Process> */
    private array $processes = [];

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
     * Handle shutdown signals.
     */
    #[NoReturn]
    public function handleSignal(
        int $signal,
    ): void {
        $this->shutdown = true;

        switch ($signal) {
            case SIGINT:
                if ($this->io) {
                    $this->io->newLine();
                    $this->io->warning('[INTERRUPT] Received Ctrl+C - shutting down gracefully...');
                }

                break;

            case SIGTERM:
                $this->io?->warning('[TERMINATE] Received termination signal - shutting down gracefully...');

                break;
        }

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
     * Terminate all tracked processes gracefully.
     */
    public function terminateAll(): void
    {
        $this->io?->text('[SHUTDOWN] Terminating all background processes...');

        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $this->io?->text(sprintf('[STOPPING] Terminating %s process (PID: %d)', $name, $process->getPid()));
                $process->stop(2); // Graceful termination with 2-second timeout
            }
        }

        // Force kill any remaining processes (stop() already handles the timeout)
        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $this->io?->warning(sprintf('[FORCE-KILL] Forcefully killing %s process', $name));
                $process->signal(9); // SIGKILL
            }
        }

        $this->io?->success('[SHUTDOWN] All processes terminated');
    }

    /**
     * Execute a single process with interactive/foreground mode handling.
     *
     * @param array  $arguments     Command arguments
     * @param bool   $isInteractive Whether to run in interactive mode
     * @param string $serviceName   Name of the service for logging
     *
     * @return int Command exit code
     */
    public static function executeProcess(
        array $arguments,
        bool $isInteractive,
        string $serviceName = 'Service',
    ): int {
        $process = new Process(['php', 'bin/console', ...$arguments]);

        if ($isInteractive) {
            try {
                $process->start();

                // Give process time to start
                usleep(500000); // 500ms

                if ($process->isRunning()) {
                    // Process started successfully - let it run in background
                    echo sprintf("[RUNNING] %s started and monitoring files for changes\n", $serviceName);

                    return Command::SUCCESS;
                }

                // Process finished quickly - check if it was successful
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                // Timeout is expected for watch services - they run continuously
                if ($process->isRunning()) {
                    // Let it continue running in the background
                    return Command::SUCCESS;
                }

                // If it stopped, check if it was successful
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            }
        } else {
            // Non-interactive mode - just run without output
            $process->run();

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        }
    }
}
