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

namespace ValksorDev\Build\Service;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Valksor\Component\Sse\Service\AbstractService;
use Valksor\Functions\Local\Traits\_MkDir;

use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function closedir;
use function count;
use function dirname;
use function file_put_contents;
use function function_exists;
use function getmypid;
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
use function unlink;
use function usort;

use const DIRECTORY_SEPARATOR;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;

final class TailwindService extends AbstractService
{
    private const float WATCH_DEBOUNCE_SECONDS = 0.25;
    private ?string $activeAppId = null;
    private PathFilter $filter;

    private bool $running = false;
    private bool $shouldReload = false;
    private bool $shouldShutdown = false;

    /** @var array<int,string> */
    private array $tailwindCommandBase = [];

    public function __construct(
        private readonly ParameterBagInterface $bag,
    ) {
        $this->filter = PathFilter::createDefault();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function reload(): void
    {
        $this->shouldReload = true;
    }

    public function removePidFile(
        string $pidFile,
    ): void {
        if (is_file($pidFile)) {
            @unlink($pidFile);
        }
    }

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

        $commandBase = $this->resolveTailwindCommandBase();
        $this->tailwindCommandBase = $commandBase['command'];
        $tailwindCommandDisplay = $commandBase['display'];

        $sources = $this->collectTailwindSources((bool) $watchMode);

        if ([] === $sources) {
            $this->io->warning('No *.tailwind.css sources found.');

            return Command::SUCCESS;
        }

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

    public function stop(): void
    {
        $this->shouldShutdown = true;
        $this->running = false;
    }

    public function writePidFile(
        string $pidFile,
    ): void {
        $pid = getmypid();
        file_put_contents($pidFile, (string) $pid);
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

        return false !== $this->bag->get('valksor.build.minify') && 'dev' !== $this->bag->get('valksor.build.env');
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

        $process = new Process($arguments, $this->bag->get('kernel.project_dir'), [
            'TAILWIND_DISABLE_NATIVE' => '1',
            'TAILWIND_DISABLE_WATCHMAN' => '1',
            'TAILWIND_DISABLE_WATCHER' => '1',
            'TAILWIND_DISABLE_FILE_DEPENDENCY_SCAN' => '1',
            'TMPDIR' => $this->ensureTempDir(),
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

        // Multi-app project structure
        if ($includeAllApps) {
            $appsDir = $this->bag->get('kernel.project_dir') . DIRECTORY_SEPARATOR . $this->bag->get('valksor.project.apps_dir');

            if (is_dir($appsDir)) {
                $handle = opendir($appsDir);

                if (false !== $handle) {
                    try {
                        while (($entry = readdir($handle)) !== false) {
                            if ('.' === $entry || '..' === $entry) {
                                continue;
                            }

                            if ($this->filter->shouldIgnoreDirectory($entry)) {
                                continue;
                            }

                            $appRoot = $appsDir . DIRECTORY_SEPARATOR . $entry;

                            if (!is_dir($appRoot)) {
                                continue;
                            }

                            $this->discoverSources($appRoot, $sources);
                        }
                    } finally {
                        closedir($handle);
                    }
                }
            }
        } elseif (null !== $this->activeAppId) {
            $appRoot = $this->bag->get('kernel.project_dir') . DIRECTORY_SEPARATOR . $this->bag->get('valksor.project.apps_dir') . '/' . $this->activeAppId;

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
        $relativeInput = trim(str_replace('\\', '/', substr($inputPath, strlen($this->bag->get('kernel.project_dir')))), '/');
        $outputPath = preg_replace('/\.tailwind\.css$/', '.css', $inputPath);
        $relativeOutput = trim(str_replace('\\', '/', substr($outputPath, strlen($this->bag->get('kernel.project_dir')))), '/');

        $label = $relativeInput;
        $watchRoots = [];

        // Multi-app project structure
        if (1 === preg_match('#^' . $this->bag->get('valksor.project.apps_dir') . '/([^/]+)/#', $relativeInput, $matches)) {
            $appName = $matches[1];
            $label = $appName;
            $watchRoots[] = $this->bag->get('kernel.project_dir') . '/' . $this->bag->get('valksor.project.apps_dir') . '/' . $appName;

            // Include shared directory if it exists
            if (is_dir($this->bag->get('kernel.project_dir') . '/' . $this->bag->get('valksor.project.infrastructure_dir'))) {
                $watchRoots[] = $this->bag->get('kernel.project_dir') . '/' . $this->bag->get('valksor.project.infrastructure_dir');
            }
        } elseif (str_starts_with($relativeInput, $this->bag->get('valksor.project.infrastructure_dir') . '/')) {
            $label = $this->bag->get('valksor.project.infrastructure_dir');
            $watchRoots[] = $this->bag->get('kernel.project_dir') . '/' . $this->bag->get('valksor.project.infrastructure_dir');
        } else {
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

    private function ensureDirectory(
        string $directory,
    ): void {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _MkDir;
            };
        }

        $_helper->mkdir($directory);
    }

    private function ensureTempDir(): string
    {
        $tmpDir = $this->bag->get('kernel.project_dir') . '/var/tmp/tailwind';

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
        $candidates = [
            $this->bag->get('kernel.project_dir') . '/var/tailwindcss/tailwindcss',
            '/usr/local/bin/tailwindcss',
            $this->bag->get('kernel.project_dir') . '/vendor/bin/tailwindcss',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Tailwind executable not found. Run "bin/console valksor:binary tailwindcss" to download it, or install it system-wide (e.g. /usr/local/bin/tailwindcss) or via vendor/bin.');
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
