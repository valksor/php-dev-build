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
use Valksor\Component\Sse\Helper;

use function dirname;
use function filemtime;
use function getcwd;
use function is_file;
use function time;
use function touch;

/**
 * Provider for binary management service.
 * Downloads and manages required binary tools.
 */
final class BinariesProvider implements ProviderInterface
{
    use Helper;

    public function build(
        array $options,
    ): int {
        // In production mode, also ensure binaries are available (with caching)
        $cacheDuration = $options['cache_duration'] ?? 3600;
        $projectRoot = getcwd();
        $cacheFile = $projectRoot . '/var/cache/binary-check.cache';

        // Skip binary check if recently verified
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheDuration) {
            return Command::SUCCESS; // Skip, binaries are current
        }

        // Ensure binaries are available
        $process = new Process(['php', 'bin/console', 'valksor:binaries:install']);
        $process->run();

        if (!$process->isSuccessful()) {
            return Command::FAILURE;
        }

        // Update cache timestamp
        $cacheDir = dirname($cacheFile);

        $this->ensureDirectory($cacheDir);
        touch($cacheFile);

        return Command::SUCCESS;
    }

    public function getDependencies(): array
    {
        return []; // No dependencies
    }

    public function getName(): string
    {
        return 'binaries';
    }

    public function getServiceOrder(): int
    {
        return 10; // Always run first
    }

    public function init(
        array $options,
    ): void {
        // Check if we should skip binary installation due to caching
        $cacheDuration = $options['cache_duration'] ?? 3600; // 1 hour default
        $projectRoot = getcwd();
        $cacheFile = $projectRoot . '/var/cache/binary-check.cache';

        // Skip binary check if recently verified
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheDuration) {
            return; // Skip network calls, binaries recently verified
        }

        // Download/ensure binaries are available
        $process = new Process(['php', 'bin/console', 'valksor:binaries:install']);
        $process->run();

        // Update cache timestamp after successful check
        if ($process->isSuccessful()) {
            // Ensure cache directory exists
            $cacheDir = dirname($cacheFile);

            $this->ensureDirectory($cacheDir);
            touch($cacheFile);
        }
    }

    public function watch(
        array $options,
    ): int {
        // Binaries are only needed for init phase
        return Command::SUCCESS;
    }
}
