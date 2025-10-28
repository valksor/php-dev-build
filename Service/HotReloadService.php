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

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Valksor\Component\Sse\Service\AbstractService;

use function array_key_exists;
use function array_keys;
use function closedir;
use function count;
use function file_put_contents;
use function is_dir;
use function is_file;
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
use function unlink;
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

    public static function getServiceName(): string
    {
        return 'hot-reload';
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

        $watchDirs = $this->bag->get('valksor.build.hot_reload.watch_dirs');
        $debounceDelay = $this->bag->get('valksor.build.hot_reload.debounce_delay');
        $extendedExtensions = $this->bag->get('valksor.build.hot_reload.extended_extensions');
        $extendedSuffixes = $this->bag->get('valksor.build.hot_reload.extended_suffixes');
        $fileTransformations = $this->bag->get('valksor.build.hot_reload.file_transformations') ?? [];

        $this->fileTransformations = $fileTransformations;
        $this->outputFiles = $this->discoverOutputFiles($this->bag->get('kernel.project_dir'), $fileTransformations);

        $watcher = new RecursiveInotifyWatcher($this->filter, function (string $path) use ($extendedSuffixes, $debounceDelay, $extendedExtensions): void {
            $this->handleFilesystemChange($path, $extendedSuffixes, $extendedExtensions, $debounceDelay);
        });

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

        while ($this->running) {
            if ($watcher) {
                $read = [$watcher->getStream()];
                $write = null;
                $except = null;

                $timeout = $this->calculateSelectTimeout();
                $seconds = $timeout >= 1 ? (int) $timeout : 0;
                $microseconds = $timeout >= 1 ? (int) (($timeout - $seconds) * 1_000_000) : (int) max($timeout * 1_000_000, 50_000);

                $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

                if (false !== $ready && !empty($read)) {
                    $watcher->poll();
                }

                $this->flushPendingReloads();
            } else {
                // No watcher available, just wait
                usleep(100000); // 100ms
            }
        }

        return 0;
    }

    public function stop(): void
    {
        $this->running = false;
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

        // Write signal file for SSE service (optimized for fast response)
        $signalFile = $this->bag->get('kernel.project_dir') . '/var/run/valksor-reload.signal';
        $signalData = json_encode(['files' => $files, 'timestamp' => microtime(true)]);
        file_put_contents($signalFile, $signalData);

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
