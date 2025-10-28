<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Service\Tailwind;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValksorDev\Build\Watcher\PathFilter;
use ValksorDev\Build\Watcher\RecursiveInotifyWatcher;

use function array_key_exists;
use function function_exists;
use function is_array;
use function microtime;
use function pcntl_async_signals;
use function pcntl_signal;
use function stream_select;
use function str_starts_with;

use const SIGHUP;
use const SIGINT;
use const SIGTERM;

/**
 * Service for watching Tailwind CSS sources and rebuilding on changes.
 */
final class TailwindWatcher
{
    private const float WATCH_DEBOUNCE_SECONDS = 0.25;

    private bool $running = false;
    private bool $shouldReload = false;
    private bool $shouldShutdown = false;

    public function __construct(
        private readonly TailwindBuilder $builder,
        private readonly PathFilter $filter,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function reload(): void
    {
        $this->shouldReload = true;
    }

    public function stop(): void
    {
        $this->shouldShutdown = true;
        $this->running = false;
    }

    /**
     * @param array<int,array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}> $sources
     */
    public function watchSources(array $sources, bool $minify, SymfonyStyle $io): int
    {
        if (!function_exists('pcntl_async_signals')) {
            $io->error('Watch mode requires the pcntl extension.');
            return Command::FAILURE;
        }

        $io->section('Entering watch mode. Press CTRL+C to stop.');

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

        $this->setupSignalHandlers();

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
                $io->newLine();
                $io->section('Reloading Tailwind build...');
                $this->shouldReload = false;

                // Rebuild all sources
                foreach ($sources as $source) {
                    $this->builder->buildSingleSource($source, $minify, null, $io);
                }

                $io->success('Tailwind reloaded.');
            }

            if ([] !== $pending && microtime(true) >= $debounceDeadline) {
                $snapshot = $pending;
                $pending = [];

                foreach ($snapshot as $source) {
                    $this->builder->buildSingleSource($source, $minify, null, $io);
                }
            }
        }

        $io->newLine();
        $io->success('Tailwind watch terminated.');

        return Command::SUCCESS;
    }

    private function setupSignalHandlers(): void
    {
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
    }
}
