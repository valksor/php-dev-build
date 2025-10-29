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

namespace ValksorDev\Build\Provider;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

/**
 * Provider for hot reload service (development only with file watching).
 */
final class HotReloadProvider implements ProviderInterface
{
    public function build(
        array $options,
    ): int {
        // Hot reload is development only, nothing to build
        return Command::SUCCESS;
    }

    public function getDependencies(): array
    {
        return []; // No dependencies
    }

    public function getName(): string
    {
        return 'hot_reload';
    }

    public function getServiceOrder(): int
    {
        return 30; // Run after binaries and tailwind
    }

    public function init(
        array $options,
    ): void {
        // Hot reload doesn't need initialization
    }

    public function watch(
        array $options,
    ): int {
        // Hot reload command now gets configuration directly from the service
        // No need to pass command-line options - configuration is handled internally
        $arguments = ['valksor:hot-reload'];
        $isInteractive = $options['interactive'] ?? true;

        $process = new Process(['php', 'bin/console', ...$arguments]);

        if ($isInteractive) {
            // Interactive mode - stream output in real-time for watch services
            // No timeouts - let the process run indefinitely
            try {
                $process->start();

                // Give process time to start
                usleep(500000); // 500ms

                if ($process->isRunning()) {
                    // Process started successfully - let it run in background
                    echo "[RUNNING] Hot reload service started and monitoring files for changes\n";

                    return Command::SUCCESS;
                }

                // Process finished quickly - check if it was successful
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                // Timeout is expected behavior for watch services - they run continuously
                if ($process->isRunning()) {
                    // Let it continue running in the background
                    return Command::SUCCESS;
                }

                // If it stopped, check if it was successful
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            }
        } else {
            // Non-interactive mode - just run without output
            $process->start();

            // Give minimal time for process to start
            usleep(250000); // 250ms

            return Command::SUCCESS; // Always return success for non-interactive hot reload
        }
    }
}
