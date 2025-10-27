<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Provider;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

use function array_merge;

use const PHP_BINARY;

/**
 * Context object providing utilities and information to dev service providers.
 *
 * This allows service providers to create processes and access project information
 * without being tightly coupled to DevWatchService.
 */
final class ServiceContext
{
    /**
     * @param string             $projectRoot   Absolute path to project root
     * @param string             $devAppBin     Path to dev app console binary
     * @param array<int, string> $availableApps List of available app IDs
     * @param SymfonyStyle       $io            Console I/O for output
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $devAppBin,
        public readonly array $availableApps,
        public readonly SymfonyStyle $io,
    ) {
    }

    /**
     * Create a process that runs a console command.
     *
     * @param array<int, string> $arguments Command arguments (e.g., ['messenger:consume', 'sentry'])
     *
     * @return Process Configured but not started process
     */
    public function createConsoleProcess(
        array $arguments,
    ): Process {
        $phpBinary = PHP_BINARY;
        $consoleBin = $this->projectRoot . '/bin/console';
        $commandLine = array_merge([$phpBinary, $consoleBin], $arguments);
        $process = new Process($commandLine, $this->projectRoot);
        $process->setTimeout(null);

        return $process;
    }

    /**
     * Create a process that runs a dev app command.
     *
     * @param array<int, string> $arguments Command arguments (e.g., ['valksor:tailwind', '--watch'])
     *
     * @return Process Configured but not started process
     */
    public function createDevAppProcess(
        array $arguments,
    ): Process {
        $phpBinary = PHP_BINARY;
        $commandLine = array_merge([$phpBinary, $this->devAppBin], $arguments);
        $process = new Process($commandLine, $this->projectRoot);
        $process->setTimeout(null);

        return $process;
    }
}
