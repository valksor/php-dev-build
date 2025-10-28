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

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function array_merge;
use function count;
use function dirname;
use function is_dir;
use function is_executable;
use function is_file;
use function mkdir;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Service for building Tailwind CSS files.
 */
final class TailwindBuilder
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param array<int,array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>}> $sources
     */
    public function buildSources(array $sources, bool $minify, SymfonyStyle $io): int
    {
        $commandBase = $this->resolveTailwindCommandBase();
        $tailwindCommand = $commandBase['command'];

        $io->section(sprintf('Building Tailwind CSS for %d source%s', count($sources), 1 === count($sources) ? '' : 's'));
        $io->note(sprintf('Using Tailwind command: %s', $commandBase['display']));

        foreach ($sources as $source) {
            $result = $this->buildSingleSource($source, $minify, $tailwindCommand, $io);

            if (Command::SUCCESS !== $result) {
                return $result;
            }
        }

        $io->success('Tailwind build completed.');

        return Command::SUCCESS;
    }

    /**
     * @param array{input:string,output:string,relative_input:string,relative_output:string,label:string,watchRoots:array<int,string>} $source
     */
    public function buildSingleSource(array $source, bool $minify, array $tailwindCommand = null, SymfonyStyle $io = null): int
    {
        $outputPath = $source['output'];
        $relativeInput = $source['relative_input'];
        $relativeOutput = $source['relative_output'];
        $label = $source['label'];

        $this->ensureDirectory(dirname($outputPath));

        if (null === $tailwindCommand) {
            $commandBase = $this->resolveTailwindCommandBase();
            $tailwindCommand = $commandBase['command'];
        }

        if (null === $io) {
            throw new \RuntimeException('SymfonyStyle is required for building');
        }

        $arguments = array_merge($tailwindCommand, ['--input', $relativeInput, '--output', $relativeOutput]);

        if ($minify) {
            $arguments[] = '--minify';
        }

        $process = new Process($arguments, $this->projectRoot, [
            'TAILWIND_DISABLE_NATIVE' => '1',
            'TAILWIND_DISABLE_WATCHMAN' => '1',
            'TAILWIND_DISABLE_WATCHER' => '1',
            'TAILWIND_DISABLE_FILE_DEPENDENCY_SCAN' => '1',
            'TMPDIR' => $this->ensureTempDir(),
        ]);
        $process->setTimeout(null);

        $io->text(sprintf('• %s', $label));

        try {
            $process->mustRun(function ($type, $buffer) use ($label, $io): void {
                if ($io->isVeryVerbose()) {
                    $prefix = sprintf('[tailwind:%s] ', $label);
                    $io->write($prefix . $buffer);
                }
            });
        } catch (ProcessFailedException $exception) {
            $io->error(sprintf('Tailwind build failed for %s: %s', $label, $exception->getProcess()->getErrorOutput() ?: $exception->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
    }

    private function ensureTempDir(): string
    {
        $tmpDir = $this->projectRoot . '/var/tmp/tailwind';

        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0o775, true);
        }

        return $tmpDir;
    }

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
            $this->projectRoot . '/var/tailwindcss/tailwindcss',
            '/usr/local/bin/tailwindcss',
            $this->projectRoot . '/vendor/bin/tailwindcss',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Tailwind executable not found. Run "bin/console valksor:binary tailwindcss" to download it, or install it system-wide (e.g. /usr/local/bin/tailwindcss) or via vendor/bin.');
    }
}
