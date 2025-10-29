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
 * Provider for Importmap service.
 */
final class ImportmapProvider implements ProviderInterface
{
    public function build(
        array $options,
    ): int {
        // Check if we should force minification in production
        $isProductionEnvironment = ($options['environment'] ?? 'dev') === 'prod';
        $shouldMinify = $isProductionEnvironment || ($options['minify'] ?? false);

        // Get available apps from the project
        $projectDir = getcwd(); // Assumes we're in the project root
        $appsDir = $projectDir . '/apps';

        if (is_dir($appsDir)) {
            $apps = array_filter(scandir($appsDir), fn ($item) => '.' !== $item && '..' !== $item && is_dir($appsDir . '/' . $item));

            foreach ($apps as $app) {
                $arguments = ['valksor:importmap', '--id', $app];

                // Add minification automatically in production or if explicitly requested
                if ($shouldMinify) {
                    $arguments[] = '--minify';
                }

                $process = new Process(['php', 'bin/console', ...$arguments]);
                $process->run();

                if (!$process->isSuccessful()) {
                    return Command::FAILURE;
                }
            }
        } else {
            // Fallback to running without app ID if no apps directory
            $arguments = ['valksor:importmap'];

            // Add minification automatically in production or if explicitly requested
            if ($shouldMinify) {
                $arguments[] = '--minify';
            }

            $process = new Process(['php', 'bin/console', ...$arguments]);
            $process->run();

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    public function getDependencies(): array
    {
        return ['binaries']; // Ensure binaries run first
    }

    public function getName(): string
    {
        return 'importmap';
    }

    public function getServiceOrder(): int
    {
        return 25; // Run after binaries and tailwind, before hot_reload
    }

    public function init(
        array $options,
    ): void {
        // Importmap doesn't need initialization
    }

    public function watch(
        array $options,
    ): int {
        $arguments = ['valksor:importmap', '--watch'];
        $isInteractive = $options['interactive'] ?? true;

        // Add watch option if configured
        if ($options['watch'] ?? true) {
            // Already in watch mode
        }

        $process = new Process(['php', 'bin/console', ...$arguments]);

        if ($isInteractive) {
            // Interactive mode - show output and handle gracefully
            $process->setTimeout(20); // 20 second timeout for startup
            $process->setIdleTimeout(15); // 15 seconds idle timeout

            try {
                $process->start();

                // Stream output to show what's happening
                $startTime = time();
                $maxStartupTime = 20;

                echo "[INITIALIZING] Importmap service - setting up JavaScript dependency management\n";

                while ($process->isRunning() && (time() - $startTime) < $maxStartupTime) {
                    // Check if we have any output
                    if ($process->getIncrementalOutput()) {
                        echo $process->getIncrementalOutput();
                    }

                    if ($process->getIncrementalErrorOutput()) {
                        echo $process->getIncrementalErrorOutput();
                    }

                    usleep(100000); // 100ms
                }

                // If process is still running after startup, that's success for importmap watch
                if ($process->isRunning()) {
                    echo "[RUNNING] Importmap service is monitoring JavaScript dependencies\n";

                    return Command::SUCCESS;
                }

                // Process finished - check if it was successful
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                // Timeout is expected behavior for watch services
                if ($process->isRunning()) {
                    echo "[RUNNING] Importmap service started successfully (continuing in background)\n";

                    return Command::SUCCESS;
                }

                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            }
        } else {
            // Non-interactive mode - just run without output
            $process->setTimeout(15);
            $process->setIdleTimeout(10);

            try {
                $process->run();

                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                // Expected for watch services
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            }
        }
    }
}
