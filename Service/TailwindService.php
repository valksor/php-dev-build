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

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Valksor\Component\Sse\Service\AbstractService;

use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function closedir;
use function count;
use function dirname;
use function function_exists;
use function is_array;
use function is_dir;
use function is_executable;
use function is_file;
use function microtime;
use function opendir;
use function pcntl_async_signals;
use function pcntl_signal;
use function preg_match;
use function preg_replace;
use function readdir;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stream_select;
use function strlen;
use function substr;
use function trim;
use function usort;

use const DIRECTORY_SEPARATOR;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;

/**
 * Tailwind CSS build service for compiling and watching Tailwind stylesheets.
 *
 * This service handles:
 * - Building individual Tailwind CSS files
 * - Watch mode with file system monitoring
 * - Multi-app project structure support
 * - Integration with the Valksor build system
 */
final class TailwindService extends AbstractService
{
    /**
     * Debounce delay for watch mode to prevent excessive rebuilds
     * when multiple files change rapidly (e.g., during git operations).
     */
    private const float WATCH_DEBOUNCE_SECONDS = 0.25;

    /**
     * Current active app ID for single-app mode.
     * When null, operates in multi-app mode watching all applications.
     */
    private ?string $activeAppId = null;

    /**
     * Path filter for ignoring directories and files during source discovery.
     */
    private PathFilter $filter;

    /**
     * Base Tailwind CLI command configuration.
     * Contains the executable path and basic command arguments.
     *
     * @var array<int,string>
     */
    private array $tailwindCommandBase = [];

    public function __construct(
        ParameterBagInterface $bag,
    ) {
        parent::__construct($bag);
        $this->filter = PathFilter::createDefault();
    }

    /**
     * Set the active application ID for single-app mode.
     *
     * When set, the service will only process Tailwind files within
     * the specified application directory. When null, operates in
     * multi-app mode watching all applications.
     *
     * @param string|null $appId The application ID or null for multi-app mode
     */
    public function setActiveAppId(
        ?string $appId,
    ): void {
        $this->activeAppId = $appId;
    }

    /**
     * @param array<string,mixed> $config Configuration: ['watch' => bool, 'minify' => bool]
     */
    public function start(
        array $config = [],
    ): int {
        $watchMode = $config['watch'] ?? false;
        $minify = $config['minify'] ?? $this->shouldMinify();

        // Resolve Tailwind CLI command and configuration
        $commandBase = $this->resolveTailwindCommandBase();
        $this->tailwindCommandBase = $commandBase['command'];
        $tailwindCommandDisplay = $commandBase['display'];

        // Discover all Tailwind CSS source files in the project
        $sources = $this->collectTailwindSources((bool) $watchMode);

        // Exit gracefully if no Tailwind sources are found
        if ([] === $sources) {
            $this->io->warning('No *.tailwind.css sources found.');

            return Command::SUCCESS;
        }

        // Sort sources by app label first, then by input file path
        // This ensures consistent processing order across different environments
        usort($sources, static function (array $left, array $right): int {
            $labelComparison = $left['label'] <=> $right['label'];

            if (0 !== $labelComparison) {
                return $labelComparison;
            }

            return $left['relative_input'] <=> $right['relative_input'];
        });

        $this->io->note(sprintf('Using Tailwind command: %s', $tailwindCommandDisplay));

        if (Command::SUCCESS !== $this->buildSources($sources, $minify)) {
            return Command::FAILURE;
        }

        if (!$watchMode) {
            return Command::SUCCESS;
        }

        if (!function_exists('pcntl_async_signals')) {
            $this->io->error('Watch mode requires the pcntl extension.');

            return Command::FAILURE;
        }

        return $this->watchSources($sources, $minify);
    }

    /**
     * Stop the Tailwind service gracefully.
     *
     * This method is called during shutdown to signal the watch loop
     * to exit cleanly and stop monitoring file changes.
     */
    public function stop(): void
    {
        $this->shouldShutdown = true;
        $this->running = false;
    }

    /**
     * Get the service name for identification in the build system.
     *
     * @return string The service identifier 'tailwind'
     */
    public static function getServiceName(): string
    {
        return 'tailwind';
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
     * @param array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>} $source
     */
    private function buildSingleSource(
        array $source,
        bool $minify,
    ): int {
        $outputPath = $source['output'];
        $relativeInput = $source['relative_input'];
        $relativeOutput = $source['relative_output'];
        $label = $source['label'];

        $this->ensureDirectory(dirname($outputPath));

        $arguments = array_merge($this->tailwindCommandBase, ['--input', $relativeInput, '--output', $relativeOutput]);

        if ($minify) {
            $arguments[] = '--minify';
        }

        // Configure Tailwind process with environment variables
        // These variables disable various Tailwind features that can cause issues
        // in development environments and ensure consistent behavior
        $process = new Process($arguments, $this->parameterBag->get('kernel.project_dir'), [
            'TAILWIND_DISABLE_NATIVE' => '1',           // Disable native CSS compiler for compatibility
            'TAILWIND_DISABLE_WATCHMAN' => '1',         // Disable Facebook Watchman for better cross-platform support
            'TAILWIND_DISABLE_WATCHER' => '1',          // Disable Tailwind's built-in file watcher (we use our own)
            'TAILWIND_DISABLE_FILE_DEPENDENCY_SCAN' => '1', // Disable automatic file dependency scanning
            'TMPDIR' => $this->ensureTempDir(),         // Use project-specific temp directory for isolation
        ]);
        $process->setTimeout(null);

        $this->io->text(sprintf('• %s', $label));

        try {
            $process->mustRun(function ($type, $buffer) use ($label): void {
                if ($this->io->isVeryVerbose()) {
                    $prefix = sprintf('[tailwind:%s] ', $label);
                    $this->io->write($prefix . $buffer);
                }
            });
        } catch (ProcessFailedException $exception) {
            $this->io->error(sprintf('Tailwind build failed for %s: %s', $label, $exception->getProcess()->getErrorOutput() ?: $exception->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<int,array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}> $sources
     */
    private function buildSources(
        array $sources,
        bool $minify,
    ): int {
        $this->io->section(sprintf('Building Tailwind CSS for %d source%s', count($sources), 1 === count($sources) ? '' : 's'));

        foreach ($sources as $source) {
            $result = $this->buildSingleSource($source, $minify);

            if (Command::SUCCESS !== $result) {
                return $result;
            }
        }

        $this->io->success('Tailwind build completed.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int,array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}>
     */
    private function collectTailwindSources(
        bool $includeAllApps,
    ): array {
        $sources = [];

        // Multi-app project structure discovery
        if ($includeAllApps) {
            // In watch mode, scan all application directories for Tailwind sources
            $appsDir = $this->parameterBag->get('kernel.project_dir') . DIRECTORY_SEPARATOR . $this->parameterBag->get('valksor.project.apps_dir');

            if (is_dir($appsDir)) {
                $handle = opendir($appsDir);

                if (false !== $handle) {
                    try {
                        while (($entry = readdir($handle)) !== false) {
                            if ('.' === $entry || '..' === $entry) {
                                continue;
                            }

                            // Skip ignored directories (e.g., node_modules, vendor, .git)
                            if ($this->filter->shouldIgnoreDirectory($entry)) {
                                continue;
                            }

                            $appRoot = $appsDir . DIRECTORY_SEPARATOR . $entry;

                            if (!is_dir($appRoot)) {
                                continue;
                            }

                            // Recursively discover Tailwind CSS files in this app
                            $this->discoverSources($appRoot, $sources);
                        }
                    } finally {
                        closedir($handle);
                    }
                }
            }
        } elseif (null !== $this->activeAppId) {
            // In single-app mode, only scan the specified application directory
            $appRoot = $this->parameterBag->get('kernel.project_dir') . DIRECTORY_SEPARATOR . $this->parameterBag->get('valksor.project.apps_dir') . '/' . $this->activeAppId;

            if (is_dir($appRoot)) {
                $this->discoverSources($appRoot, $sources);
            }
        }

        return $sources;
    }

    /**
     * @return array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}
     */
    private function createSourceDefinition(
        string $inputPath,
    ): array {
        // Convert absolute paths to relative paths from project root
        $relativeInput = trim(str_replace('\\', '/', substr($inputPath, strlen($this->parameterBag->get('kernel.project_dir')))), '/');

        // Generate output file path by replacing .tailwind.css with .css
        $outputPath = preg_replace('/\.tailwind\.css$/', '.css', $inputPath);
        $relativeOutput = trim(str_replace('\\', '/', substr($outputPath, strlen($this->parameterBag->get('kernel.project_dir')))), '/');

        $label = $relativeInput;
        $watchRoots = [];

        // Multi-app project structure: determine watch roots based on file location
        if (1 === preg_match('#^' . $this->parameterBag->get('valksor.project.apps_dir') . '/([^/]+)/#', $relativeInput, $matches)) {
            // File is within an app directory - watch the entire app and shared infrastructure
            $appName = $matches[1];
            $label = $appName;
            $watchRoots[] = $this->parameterBag->get('kernel.project_dir') . '/' . $this->parameterBag->get('valksor.project.apps_dir') . '/' . $appName;

            // Include shared infrastructure directory if it exists (common utilities, shared components)
            if (is_dir($this->parameterBag->get('kernel.project_dir') . '/' . $this->parameterBag->get('valksor.project.infrastructure_dir'))) {
                $watchRoots[] = $this->parameterBag->get('kernel.project_dir') . '/' . $this->parameterBag->get('valksor.project.infrastructure_dir');
            }
        } elseif (str_starts_with($relativeInput, $this->parameterBag->get('valksor.project.infrastructure_dir') . '/')) {
            // File is in shared infrastructure - watch only the infrastructure directory
            $label = $this->parameterBag->get('valksor.project.infrastructure_dir');
            $watchRoots[] = $this->parameterBag->get('kernel.project_dir') . '/' . $this->parameterBag->get('valksor.project.infrastructure_dir');
        } else {
            // File is outside the standard structure - watch its parent directory
            $watchRoots[] = dirname($inputPath);
        }

        return [
            'input' => $inputPath,
            'output' => $outputPath,
            'relative_input' => $relativeInput,
            'relative_output' => $relativeOutput,
            'label' => $label,
            'watchRoots' => array_values(array_unique($watchRoots)),
        ];
    }

    /**
     * @param array<int,array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}> $sources
     */
    private function discoverSources(
        string $directory,
        array &$sources,
    ): void {
        if (!is_dir($directory)) {
            return;
        }

        $handle = opendir($directory);

        if (false === $handle) {
            return;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                $full = $directory . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($full)) {
                    if ($this->filter->shouldIgnoreDirectory($entry)) {
                        continue;
                    }

                    $this->discoverSources($full, $sources);

                    continue;
                }

                if (!str_ends_with($entry, '.tailwind.css')) {
                    continue;
                }

                $sources[] = $this->createSourceDefinition($full);
            }
        } finally {
            closedir($handle);
        }
    }

    private function ensureTempDir(): string
    {
        $tmpDir = $this->parameterBag->get('kernel.project_dir') . '/var/tmp/tailwind';

        $this->ensureDirectory($tmpDir);

        return $tmpDir;
    }

    /**
     * Get search roots for single-app projects
     * Can be customized via ASSET_ROOTS environment variable or injected watch directories.
     *
     * @return array<int,string>
     */
    private function resolveTailwindCommandBase(): array
    {
        $binary = $this->resolveTailwindExecutable();

        return [
            'display' => $binary,
            'command' => [$binary],
        ];
    }

    private function resolveTailwindExecutable(): string
    {
        // Use project-local Tailwind binary downloaded via valksor:binary command
        $tailwindBinary = $this->parameterBag->get('kernel.project_dir') . '/var/tailwindcss/tailwindcss';

        if (!is_file($tailwindBinary) || !is_executable($tailwindBinary)) {
            throw new RuntimeException('Tailwind executable not found at ' . $tailwindBinary . '. Run "bin/console valksor:binary tailwindcss" to download it.');
        }

        return $tailwindBinary;
    }

    /**
     * @param array<int,array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}> $sources
     */
    private function watchSources(
        array $sources,
        bool $minify,
    ): int {
        $this->io->section('Entering watch mode. Press CTRL+C to stop.');

        $this->running = true;
        $this->shouldReload = false;
        $this->shouldShutdown = false;

        $pending = [];
        $debounceDeadline = 0.0;
        $outputPaths = [];
        $rootToSources = [];

        foreach ($sources as $source) {
            $outputPaths[$source['output']] = true;

            foreach ($source['watchRoots'] as $root) {
                $rootToSources[$root][$source['input']] = $source;
            }
        }

        $watcher = new RecursiveInotifyWatcher($this->filter, function (string $path) use (&$pending, &$debounceDeadline, $outputPaths, $rootToSources): void {
            if (is_array($outputPaths) ? array_key_exists($path, $outputPaths) : isset($outputPaths[$path])) {
                return;
            }

            foreach ($rootToSources as $root => $sourcesForRoot) {
                if (!str_starts_with($path, $root)) {
                    continue;
                }

                foreach ($sourcesForRoot as $source) {
                    $pending[$source['input']] = $source;
                    $debounceDeadline = microtime(true) + self::WATCH_DEBOUNCE_SECONDS;
                }
            }
        });

        foreach (array_keys($rootToSources) as $root) {
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
                $this->io->section('Reloading Tailwind build...');
                $this->shouldReload = false;

                // Rebuild all sources
                foreach ($sources as $source) {
                    $this->buildSingleSource($source, $minify);
                }

                $this->io->success('Tailwind reloaded.');
            }

            if ([] !== $pending && microtime(true) >= $debounceDeadline) {
                $snapshot = $pending;
                $pending = [];

                foreach ($snapshot as $source) {
                    $this->buildSingleSource($source, $minify);
                }
            }
        }

        $this->io->newLine();
        $this->io->success('Tailwind watch terminated.');

        return Command::SUCCESS;
    }
}
