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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Valksor\Component\Sse\Service\AbstractService;

use function array_key_exists;
use function array_keys;
use function closedir;
use function count;
use function extension_loaded;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function ltrim;
use function max;
use function microtime;
use function opendir;
use function pathinfo;
use function preg_match;
use function preg_quote;
use function readdir;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function stream_select;
use function strtolower;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

final class HotReloadService extends AbstractService
{
    private float $debounceDeadline = 0.0;

    /** @var array<string,array<string,mixed>> */
    private array $fileTransformations = [];
    private PathFilter $filter;
    private float $lastContentChange = 0.0;

    /** @var array<string,bool> */
    private array $outputFiles = [];

    /** @var array<string,bool> */
    private array $pendingChanges = [];

    public function __construct(
        ParameterBagInterface $bag,
    ) {
        parent::__construct($bag);
        $this->filter = PathFilter::createDefault();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function reload(): void
    {
        // Hot reload service doesn't support manual reload
        if ($this->io) {
            $this->io->note('Hot reload service reload requested (no action needed)');
        }
    }

    /**
     * @param array<string,mixed> $config Configuration from hot_reload config section
     */
    public function start(
        array $config = [],
    ): int {
        // SSE server communication now handled via direct service calls

        // Get hot reload configuration from new service-based structure
        $hotReloadConfig = $this->bag->get('valksor.build.services.hot_reload.options', []);

        // Validate configuration early
        if (!$this->validateConfiguration($hotReloadConfig)) {
            return 1; // Return error code for invalid config
        }

        $watchDirs = $hotReloadConfig['watch_dirs'] ?? [];

        // Early return if no watch directories configured
        if (empty($watchDirs)) {
            if ($this->io) {
                $this->io->warning('No watch directories configured. Hot reload disabled.');
            }

            return Command::SUCCESS;
        }

        $debounceDelay = $hotReloadConfig['debounce_delay'] ?? 0.3;
        $extendedExtensions = $hotReloadConfig['extended_extensions'] ?? [];
        $extendedSuffixes = $hotReloadConfig['extended_suffixes'] ?? ['.tailwind.css' => 0.5];
        $fileTransformations = $hotReloadConfig['file_transformations'] ?? [
            '*.tailwind.css' => [
                'output_pattern' => '{path}/{name}.css',
                'debounce_delay' => 0.5,
                'track_output' => true,
            ],
        ];

        $this->fileTransformations = $fileTransformations;
        // Lazy load output files only when needed, not during startup
        $this->outputFiles = [];

        // Initialize watcher with error handling
        $watcher = null;

        try {
            $watcher = new RecursiveInotifyWatcher($this->filter, function (string $path) use ($extendedSuffixes, $debounceDelay, $extendedExtensions): void {
                $this->handleFilesystemChange($path, $extendedSuffixes, $extendedExtensions, $debounceDelay);
            });

            if ($this->io) {
                $this->io->text('File watcher initialized successfully');
            }
        } catch (RuntimeException $e) {
            if ($this->io) {
                $this->io->error(sprintf('Failed to initialize file watcher: %s', $e->getMessage()));
                $this->io->warning('Hot reload will continue without file watching (manual reload only)');
            }

            // Continue without watcher - will fall back to manual mode
            $watcher = null;
        } catch (Exception $e) {
            if ($this->io) {
                $this->io->error(sprintf('Unexpected error initializing watcher: %s', $e->getMessage()));
            }

            // Continue without watcher
            $watcher = null;
        }

        $watchTargets = $this->collectWatchTargets($this->bag->get('kernel.project_dir'), $watchDirs);

        if ([] === $watchTargets) {
            if ($this->io) {
                $this->io->warning('No watch targets found. Hot reload will remain idle.');
            }
        } else {
            foreach ($watchTargets as $target) {
                $watcher->addRoot($target);
            }

            if ($this->io) {
                $this->io->note(sprintf('Watching %d directories for changes', count($watchTargets)));
            }
        }

        $this->running = true;
        $this->lastContentChange = microtime(true);

        if ($this->io) {
            $this->io->success('Hot reload service started');
        }

        // Add loop timeout protection - prevent infinite hangs
        $loopCount = 0;
        $maxLoopsWithoutActivity = 6000; // 10 minutes at 100ms intervals

        while ($this->running) {
            $loopCount++;

            // Timeout protection - exit if no activity for too long
            if ($loopCount > $maxLoopsWithoutActivity && empty($this->pendingChanges)) {
                if ($this->io) {
                    $this->io->warning('Hot reload service timeout - no activity for 10 minutes, exiting gracefully');
                }

                break;
            }

            if ($watcher) {
                $read = [$watcher->getStream()];
                $write = null;
                $except = null;

                $timeout = $this->calculateSelectTimeout();
                $seconds = $timeout >= 1 ? (int) $timeout : 0;
                $microseconds = $timeout >= 1 ? (int) (($timeout - $seconds) * 1_000_000) : (int) max($timeout * 1_000_000, 50_000);

                // Add error handling around stream_select
                try {
                    $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

                    if (false === $ready) {
                        // stream_select failed, likely due to signal interruption
                        if ($this->io && 0 === $loopCount % 100) { // Log every 10 seconds
                            $this->io->text('Stream select interrupted, continuing...');
                        }
                        usleep(100000); // Wait 100ms before retry

                        continue;
                    }

                    if (!empty($read)) {
                        $watcher->poll();
                        // Reset loop counter on activity
                        $loopCount = 0;
                    }

                    $this->flushPendingReloads();
                } catch (Exception $e) {
                    if ($this->io) {
                        $this->io->error(sprintf('Error in file watching loop: %s', $e->getMessage()));
                    }
                    // Continue running but log the error
                }
            } else {
                // No watcher available, just wait with timeout
                usleep(100000); // 100ms

                // Reset loop counter periodically to prevent false timeouts
                if (0 === $loopCount % 300) { // Every 30 seconds
                    $loopCount = 0;
                }
            }
        }

        return 0;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public static function getServiceName(): string
    {
        return 'hot-reload';
    }

    private function calculateSelectTimeout(): float
    {
        $now = microtime(true);

        if ($this->debounceDeadline > 0) {
            return max(0.05, $this->debounceDeadline - $now);
        }

        return 1.0;
    }

    /**
     * @return array<int,string>
     */
    private function collectWatchTargets(
        string $projectRoot,
        array $watchDirs,
    ): array {
        $targets = [];

        foreach ($watchDirs as $dir) {
            $fullPath = $projectRoot . DIRECTORY_SEPARATOR . ltrim($dir, '/');

            if (is_dir($fullPath)) {
                $targets[] = $fullPath;
            }
        }

        return $targets;
    }

    private function determineReloadDelay(
        string $path,
        array $extendedExtensions,
        array $extendedSuffixes,
        float $debounceDelay,
    ): float {
        // Lazy load output files if not already discovered
        if (empty($this->outputFiles)) {
            $this->outputFiles = $this->discoverOutputFiles($this->bag->get('kernel.project_dir'), $this->fileTransformations);
        }

        // Check if this is a tracked output file first
        // If it's a tracked output file, use transformation's debounce delay
        if (isset($this->outputFiles[$path])) {
            foreach ($this->fileTransformations as $pattern => $config) {
                if ($this->matchesPattern($path, $pattern)) {
                    return $config['debounce_delay'] ?? $debounceDelay;
                }
            }
        }

        // For non-output files, check file transformations
        foreach ($this->fileTransformations as $pattern => $config) {
            if ($this->matchesPattern($path, $pattern)) {
                return $config['debounce_delay'] ?? $debounceDelay;
            }
        }

        // Check extended suffixes (legacy support)
        foreach ($extendedSuffixes as $suffix => $delay) {
            if (str_ends_with($path, $suffix)) {
                return $delay;
            }
        }

        // Check extended extensions
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ('' !== $extension && array_key_exists($extension, $extendedExtensions)) {
            return $extendedExtensions[$extension];
        }

        return $debounceDelay;
    }

    /**
     * @param array<string,array<string,mixed>> $transformations
     *
     * @return array<string,bool>
     */
    private function discoverOutputFiles(
        string $projectRoot,
        array $transformations,
    ): array {
        $outputs = [];

        foreach ($transformations as $pattern => $config) {
            if (!($config['track_output'] ?? true)) {
                continue;
            }

            $roots = $config['watch_dirs'] ?? [
                $projectRoot . $this->bag->get('valksor.project.apps_dir'),
                $projectRoot . $this->bag->get('valksor.project.infrastructure_dir'),
            ];

            foreach ($roots as $root) {
                $root = $projectRoot . DIRECTORY_SEPARATOR . ltrim($root, '/');

                if (!is_dir($root)) {
                    continue;
                }

                $this->visitSources($root, $pattern, $config['output_pattern'], $outputs);
            }
        }

        return $outputs;
    }

    private function flushPendingReloads(): void
    {
        if ([] === $this->pendingChanges || microtime(true) < $this->debounceDeadline) {
            return;
        }

        $files = array_keys($this->pendingChanges);
        $this->pendingChanges = [];
        $this->debounceDeadline = 0.0;

        // Write signal file for SSE service with error handling
        $signalFile = $this->bag->get('kernel.project_dir') . '/var/run/valksor-reload.signal';
        $signalData = json_encode(['files' => $files, 'timestamp' => microtime(true)]);

        try {
            $result = file_put_contents($signalFile, $signalData);

            if (false === $result) {
                throw new RuntimeException('Failed to write signal file');
            }
        } catch (Exception $e) {
            if ($this->io) {
                $this->io->error(sprintf('Failed to write reload signal: %s', $e->getMessage()));
            }

            return;
        }

        if ($this->io) {
            $this->io->success(sprintf('Reload signal sent for %d changed files', count($files)));
        }
    }

    /**
     * Generate output file path from pattern.
     */
    private function generateOutputPath(
        string $projectRoot,
        string $relativeDir,
        string $filename,
        string $outputPattern,
    ): string {
        $placeholders = [
            '{path}' => $relativeDir,
            '{name}' => $filename,
        ];

        $outputPath = $outputPattern;

        foreach ($placeholders as $placeholder => $value) {
            $outputPath = str_replace($placeholder, $value, $outputPath);
        }

        return $projectRoot . DIRECTORY_SEPARATOR . $outputPath;
    }

    private function handleFilesystemChange(
        string $path,
        array $extendedSuffixes,
        array $extendedExtensions,
        float $debounceDelay,
    ): void {
        if ($this->filter->shouldIgnorePath($path)) {
            return;
        }

        $now = microtime(true);
        $isOutputFile = isset($this->outputFiles[$path]);

        // Determine the appropriate delay for this file type
        $fileDelay = $this->determineReloadDelay($path, $extendedExtensions, $extendedSuffixes, $debounceDelay);

        // Debug logging
        if ($this->io) {
            $this->io->text(sprintf(
                'DEBUG: File %s | Output: %s | Delay: %.2fs',
                $path,
                $isOutputFile ? 'YES' : 'NO',
                $fileDelay,
            ));
        }

        // Rate limiting for output files
        if ($isOutputFile && ($now - $this->lastContentChange) < $fileDelay) {
            if ($this->io) {
                $this->io->text(sprintf(
                    'DEBUG: Rate limiting %s (last change: %.2fs ago, limit: %.2fs)',
                    $path,
                    $now - $this->lastContentChange,
                    $fileDelay,
                ));
            }

            return;
        }

        // Check if this is a duplicate pending change
        if (isset($this->pendingChanges[$path])) {
            if ($this->io) {
                $this->io->text(sprintf('DEBUG: Skipping duplicate change for %s', $path));
            }

            // Don't update timing for duplicate changes
            return;
        }

        $this->pendingChanges[$path] = true;

        if (!$isOutputFile || ($now - $this->lastContentChange) >= $fileDelay) {
            $this->lastContentChange = $now;
        }

        $desiredDeadline = $now + $fileDelay;

        if ($desiredDeadline > $this->debounceDeadline) {
            $this->debounceDeadline = $desiredDeadline;
        }

        if ($this->io) {
            $this->io->text('File changed: ' . $path);
        }
    }

    /**
     * Check if a file path matches a pattern.
     */
    private function matchesPattern(
        string $path,
        string $pattern,
    ): bool {
        // Convert glob pattern to regex
        $pattern = str_replace(['*', '?'], ['.*', '.'], $pattern);
        $pattern = '#^' . preg_quote($this->bag->get('kernel.project_dir') . DIRECTORY_SEPARATOR, '#') . $pattern . '$#';

        return 1 === preg_match($pattern, $path);
    }

    /**
     * Validate hot reload configuration before starting.
     */
    private function validateConfiguration(
        array $config,
    ): bool {
        $projectRoot = $this->bag->get('kernel.project_dir');

        // Validate var/run directory exists and is writable
        $runDir = $projectRoot . '/var/run';
        $this->ensureDirectory($runDir);

        if (!is_writable($runDir)) {
            if ($this->io) {
                $this->io->error(sprintf('Run directory is not writable: %s', $runDir));
            }

            return false;
        }

        // Validate watch directories exist
        $watchDirs = $config['watch_dirs'] ?? [];

        foreach ($watchDirs as $dir) {
            $fullPath = $projectRoot . '/' . ltrim($dir, '/');

            if (!is_dir($fullPath)) {
                if ($this->io) {
                    $this->io->warning(sprintf('Watch directory does not exist: %s', $fullPath));
                }
            }
        }

        // Check if inotify extension is available
        if (!extension_loaded('inotify')) {
            if ($this->io) {
                $this->io->warning('PHP inotify extension is not available. File watching may not work optimally.');
            }
        }

        return true;
    }

    /**
     * @param array<string,bool> $outputs
     */
    private function visitSources(
        string $path,
        string $pattern,
        string $outputPattern,
        array &$outputs,
    ): void {
        $handle = opendir($path);

        if (false === $handle) {
            return;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                $full = $path . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($full)) {
                    if ($this->filter->shouldIgnoreDirectory($entry)) {
                        continue;
                    }
                    $this->visitSources($full, $pattern, $outputPattern, $outputs);

                    continue;
                }

                if (!$this->matchesPattern($full, $pattern)) {
                    continue;
                }

                // Generate output file path
                $relativePath = str_replace($this->bag->get('kernel.project_dir') . DIRECTORY_SEPARATOR, '', $full);
                $pathInfo = pathinfo($relativePath);

                $outputFile = $this->generateOutputPath(
                    $this->bag->get('kernel.project_dir'),
                    $pathInfo['dirname'] ?? '',
                    $pathInfo['filename'],
                    $outputPattern,
                );

                $outputs[$outputFile] = true;
            }
        } finally {
            closedir($handle);
        }
    }
}
