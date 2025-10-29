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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Valksor\Component\Sse\Service\AbstractService;
use Valksor\FullStack;

use function array_key_exists;
use function array_keys;
use function basename;
use function closedir;
use function copy;
use function count;
use function dirname;
use function file_exists;
use function function_exists;
use function is_array;
use function is_dir;
use function is_file;
use function opendir;
use function pcntl_async_signals;
use function pcntl_signal;
use function readdir;
use function rmdir;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_select;
use function strlen;
use function substr;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;

/**
 * Importmap service for building and watching JavaScript modules.
 *
 * This service handles:
 * - Processing JavaScript modules across multi-app structure
 * - Building with optional esbuild minification
 * - Watch mode with file system monitoring
 * - Managing shared infrastructure and app-specific assets
 * - Integration with ValkSSE component assets
 */
final class ImportmapService extends AbstractService
{
    /**
     * Path filter for ignoring directories and files during asset discovery.
     */
    private PathFilter $filter;

    public function __construct(
        ParameterBagInterface $bag,
    ) {
        parent::__construct($bag);
        $this->filter = PathFilter::createDefault();
    }

    /**
     * Check if the importmap service is currently running.
     *
     * @return bool True if the service is active and monitoring files
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Trigger a reload of all JavaScript modules.
     *
     * This method is called during watch mode to rebuild all modules
     * when a configuration change or external reload signal is received.
     */
    public function reload(): void
    {
        $this->shouldReload = true;
    }

    /**
     * @param array<string,mixed> $config Configuration: ['watch' => bool, 'minify' => bool, 'esbuild' => ?string]
     */
    public function start(
        array $config = [],
    ): int {
        $watch = $config['watch'];
        $minify = $config['minify'];
        $esbuild = $this->resolveEsbuildExecutable($minify);

        $roots = $this->collectRoots();

        if ([] === $roots) {
            $this->io->warning('No asset roots found.');

            return Command::SUCCESS;
        }

        $this->io->note(sprintf('Processing %d root%s', count($roots), 1 === count($roots) ? '' : 's'));

        $this->buildAll($roots, $esbuild, $minify);

        if (!$watch) {
            return Command::SUCCESS;
        }

        if (!function_exists('pcntl_async_signals')) {
            $this->io->error('Watch mode requires the pcntl extension which is not available.');

            return Command::FAILURE;
        }

        return $this->watchRoots($roots, $esbuild, $minify);
    }

    /**
     * Stop the importmap service gracefully.
     *
     * This method is called during shutdown to signal the watch loop
     * to exit cleanly and stop monitoring JavaScript files.
     */
    public function stop(): void
    {
        $this->shouldShutdown = true;
        $this->running = false;
    }

    /**
     * Get the service name for identification in the build system.
     *
     * @return string The service identifier 'importmap'
     */
    public static function getServiceName(): string
    {
        return 'importmap';
    }

    protected function shouldMinify(
        InputInterface $input,
    ): bool {
        if ($input->hasOption('no-minify') && $input->getOption('no-minify')) {
            return false;
        }

        if ($input->hasOption('minify') && $input->getOption('minify')) {
            return true;
        }

        return false !== $this->parameterBag->get('valksor.build.minify') && 'dev' !== $this->parameterBag->get('valksor.build.env');
    }

    /**
     * @param array<int,array{label:string,source:string,dist:string}> $roots
     */
    private function buildAll(
        array $roots,
        ?string $esbuild,
        bool $minify,
    ): void {
        // Clear and recreate all dist directories for clean builds
        foreach ($roots as $root) {
            $this->removeDirectory($root['dist']);
            $this->ensureDirectory($root['dist']);
        }

        // Collect all JavaScript modules from all root directories
        // This includes shared infrastructure, ValkSSE components, and app-specific modules
        $modules = [];

        foreach ($roots as $root) {
            foreach ($this->collectModules($root) as $module) {
                $modules[] = $module;
            }
        }

        if ([] === $modules) {
            $this->io->warning('No JavaScript modules found.');

            return;
        }

        $this->io->section(sprintf('Building %d module%s', count($modules), 1 === count($modules) ? '' : 's'));
        $failures = 0;

        foreach ($modules as $module) {
            if (!$this->writeModule($module['source'], $module['output'], $esbuild, $minify)) {
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->io->error(sprintf('Importmap sync completed with %d failure%s.', $failures, 1 === $failures ? '' : 's'));
        } else {
            $this->io->success('Importmap sync completed.');
        }
    }

    /**
     * @param array{label:string,source:string,dist:string} $root
     *
     * @return array<int,array{label:string,source:string,output:string}>
     */
    private function collectModules(
        array $root,
    ): array {
        if (!is_dir($root['source'])) {
            return [];
        }

        $modules = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root['source'], FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if ('js' !== $file->getExtension()) {
                continue;
            }

            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root['source']) + 1);
            $output = $root['dist'] . DIRECTORY_SEPARATOR . $relative;
            $modules[] = [
                'label' => $root['label'],
                'source' => $file->getPathname(),
                'output' => $output,
            ];
        }

        return $modules;
    }

    /**
     * @return array<int,array{label:string,source:string,dist:string}>
     */
    private function collectRoots(): array
    {
        $roots = [];

        // Multi-app project structure: Discover shared and component assets

        // Shared infrastructure JavaScript (common utilities, components used across apps)
        $sharedJs = $this->parameterBag->get('kernel.project_dir') . DIRECTORY_SEPARATOR . $this->parameterBag->get('valksor.project.infrastructure_dir') . '/assets/js';
        $sharedDist = $this->parameterBag->get('kernel.project_dir') . DIRECTORY_SEPARATOR . $this->parameterBag->get('valksor.project.infrastructure_dir') . '/assets/dist';

        if (is_dir($sharedJs)) {
            $roots[] = ['label' => 'shared', 'source' => $sharedJs, 'dist' => $sharedDist];
        }

        // Determine ValkSSE component assets path based on installation type
        $projectDir = $this->parameterBag->get('kernel.project_dir');

        if (is_dir($projectDir . '/valksor')) {
            // Development/local installation
            $path = '/valksor/src/Valksor/Component/Sse/Resources/assets';
        } elseif (class_exists(FullStack::class)) {
            // Full-stack package installation
            $path = '/vendor/valksor/valksor/src/Valksor/Component/Sse/Resources/assets';
        } else {
            // Standalone SSE package installation
            $path = '/vendor/valksor/php-sse/Resources/assets';
        }

        if (is_dir($projectDir . $path . '/js')) {
            $roots[] = ['label' => 'valksorsse', 'source' => $projectDir . $path . '/js', 'dist' => $sharedDist];
        }

        // Discover application-specific JavaScript modules
        $appsDir = $this->parameterBag->get('kernel.project_dir') . DIRECTORY_SEPARATOR . $this->parameterBag->get('valksor.project.apps_dir');

        if (is_dir($appsDir)) {
            $handle = opendir($appsDir);

            if (false !== $handle) {
                try {
                    while (($entry = readdir($handle)) !== false) {
                        if ('.' === $entry || '..' === $entry) {
                            continue;
                        }

                        // Each app has its own assets/js directory for app-specific modules
                        $appSource = $appsDir . DIRECTORY_SEPARATOR . $entry . '/assets/js';

                        if (!is_dir($appSource)) {
                            continue;
                        }

                        $roots[] = [
                            'label' => $entry,
                            'source' => $appSource,
                            'dist' => $appsDir . DIRECTORY_SEPARATOR . $entry . '/assets/dist',
                        ];
                    }
                } finally {
                    closedir($handle);
                }
            }
        }

        return $roots;
    }

    private function removeDirectory(
        string $directory,
    ): void {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($directory);
    }

    private function removeModule(
        string $target,
    ): void {
        if (is_file($target)) {
            unlink($target);
        }
    }

    private function resolveEsbuildExecutable(
        bool $minify,
    ): ?string {
        if (!$minify) {
            return null; // No minification needed, use simple copy
        }

        // Use project-local esbuild binary downloaded via valksor:binary command
        return $this->parameterBag->get('kernel.project_dir') . '/var/esbuild/esbuild';
    }

    /**
     * @param array<int,array{label:string,source:string,dist:string}> $roots
     */
    private function watchRoots(
        array $roots,
        ?string $esbuild,
        bool $minify,
    ): int {
        $this->io->section('Entering importmap watch mode. Press CTRL+C to stop.');

        $this->running = true;
        $this->shouldReload = false;
        $this->shouldShutdown = false;

        $rootToModules = [];
        $outputMap = [];
        $rootMetadata = [];

        foreach ($roots as $root) {
            $rootPath = $root['source'];
            $rootMetadata[$rootPath] = $root;

            if (!is_array($rootToModules) ? array_key_exists($rootPath, $rootToModules) : isset($rootToModules[$rootPath])) {
                $rootToModules[$rootPath] = [];
            }

            foreach ($this->collectModules($root) as $module) {
                $rootToModules[$rootPath][$module['source']] = $module;
                $outputMap[$module['output']] = true;
            }
        }

        $watcher = new RecursiveInotifyWatcher($this->filter, function (string $path) use (&$rootToModules, &$outputMap, $rootMetadata, $esbuild, $minify): void {
            if (is_array($outputMap) ? array_key_exists($path, $outputMap) : isset($outputMap[$path])) {
                return; // ignore writes to dist
            }

            foreach ($rootToModules as $root => $modules) {
                if (!str_starts_with($path, $root)) {
                    continue;
                }

                $relative = substr($path, strlen($root) + 1);

                if ('' === $relative || !str_ends_with($relative, '.js')) {
                    continue;
                }

                if (str_contains($relative, 'dist' . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $sourcePath = $root . DIRECTORY_SEPARATOR . $relative;
                $distRoot = $rootMetadata[$root]['dist'] ?? null;

                if (null === $distRoot) {
                    continue;
                }

                $outputPath = $distRoot . DIRECTORY_SEPARATOR . $relative;

                if (!file_exists($sourcePath)) {
                    $this->removeModule($outputPath);
                    unset($rootToModules[$root][$sourcePath], $outputMap[$outputPath]);

                    continue;
                }

                $label = $modules[$sourcePath]['label'] ?? $rootMetadata[$root]['label'] ?? basename($root);
                $module = [
                    'label' => $label,
                    'source' => $sourcePath,
                    'output' => $outputPath,
                ];

                if ($this->writeModule($module['source'], $module['output'], $esbuild, $minify)) {
                    $rootToModules[$root][$sourcePath] = $module;
                    $outputMap[$module['output']] = true;
                }
            }
        });

        foreach (array_keys($rootToModules) as $root) {
            $watcher->addRoot($root);
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (): void {
            $this->stop();
        });
        pcntl_signal(SIGTERM, function (): void {
            $this->stop();
        });
        pcntl_signal(SIGHUP, function (): void {
            $this->reload();
        });

        while ($this->running && !$this->shouldShutdown) {
            $stream = $watcher->getStream();
            $read = [$stream];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 250_000);

            if (false === $ready) {
                continue;
            }
            $watcher->poll();

            if ($this->shouldReload) {
                $this->io->newLine();
                $this->io->section('Reloading importmap sync...');
                $this->shouldReload = false;

                // Rebuild all
                $this->buildAll($roots, $esbuild, $minify);

                // Refresh watcher state
                $rootToModules = [];
                $outputMap = [];

                foreach ($roots as $root) {
                    $rootPath = $root['source'];
                    $rootToModules[$rootPath] = [];

                    foreach ($this->collectModules($root) as $module) {
                        $rootToModules[$rootPath][$module['source']] = $module;
                        $outputMap[$module['output']] = true;
                    }
                }

                $this->io->success('Importmap reloaded.');
            }
        }

        $this->io->newLine();
        $this->io->success('Importmap watch terminated.');

        return Command::SUCCESS;
    }

    private function writeModule(
        string $source,
        string $target,
        ?string $esbuild,
        bool $minify,
    ): bool {
        $this->ensureDirectory(dirname($target));

        if ($minify && null !== $esbuild) {
            // Use esbuild for minification when enabled
            $process = new Process([
                $esbuild,
                $source,
                '--outfile=' . $target,
                '--format=esm',      // ES Module format for modern browsers
                '--target=es2020',   // Target modern JavaScript features
                '--minify',          // Enable minification
            ], $this->parameterBag->get('kernel.project_dir'));
            $process->setTimeout(null);

            try {
                $process->mustRun();

                return true;
            } catch (ProcessFailedException $exception) {
                $this->io->error(sprintf('esbuild failed for %s: %s', $source, $exception->getProcess()->getErrorOutput() ?: $exception->getMessage()));

                return false;
            }
        }

        // Simple copy when minification is disabled
        if (!copy($source, $target)) {
            $this->io->error(sprintf('Failed to copy %s to %s', $source, $target));

            return false;
        }

        return true;
    }
}
