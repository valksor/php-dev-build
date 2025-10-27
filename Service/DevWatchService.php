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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;
use Valksor\Component\Sse\Service\AbstractService;
use ValksorDev\Build\Binary\BinaryRegistry;
use ValksorDev\Build\Provider\ProviderRegistry;
use ValksorDev\Build\Provider\ServiceContext;

use function array_merge;
use function file_exists;
use function file_put_contents;
use function function_exists;
use function getmypid;
use function is_dir;
use function is_file;
use function pcntl_async_signals;
use function pcntl_signal;
use function rmdir;
use function scandir;
use function sprintf;
use function str_contains;
use function unlink;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;
use const SCANDIR_SORT_NONE;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;

final class DevWatchService extends AbstractService
{
    /** @var array<string,Process> */
    private array $processes = [];
    private bool $running = false;
    private bool $shouldReload = false;
    private bool $shouldShutdown = false;

    public function __construct(
        #[Autowire(
            param: 'valksor.build.binaries',
        )]
        private readonly array $requiredBinaries,
        #[Autowire(param: 'valksor.build.services')]
        private readonly array $requiredServices,
        private readonly BinaryRegistry $binaryRegistry,
        private readonly ProviderRegistry $serviceRegistry,
        private readonly ParameterBagInterface $bag,
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

    public function removePidFile(
        string $pidFile,
    ): void {
        if (is_file($pidFile)) {
            @unlink($pidFile);
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    public function start(
        array $config = [],
    ): int {
        if (!function_exists('pcntl_async_signals')) {
            $this->io->error('The dev watch command requires the pcntl extension.');

            return Command::FAILURE;
        }

        $this->cleanPublicAssets();

        if (!$this->ensureDevBinaries()) {
            return Command::FAILURE;
        }

        $this->io->section('Starting dev tooling (Tailwind, importmap, hot reload)…');

        $this->running = true;
        $this->shouldReload = false;
        $this->shouldShutdown = false;

        return $this->runServices();
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

    private function awaitInitialBuild(
        string $label,
        Process $process,
        bool &$readyFlag,
    ): bool {
        $this->io->text(sprintf('Waiting for %s initial build to finish…', $label));

        while ($process->isRunning()) {
            if ($readyFlag) {
                return true;
            }

            usleep(100_000);
        }

        $exitStatus = $process->getExitCode();

        if (null === $exitStatus) {
            return false;
        }

        if (Command::SUCCESS === $exitStatus) {
            return true;
        }

        $this->io->error(sprintf('%s exited before reporting readiness (status %d).', $label, $exitStatus));

        return false;
    }

    private function cleanPublicAssets(): void
    {
        $publicAssets = $this->bag->get('kernel.project_dir') . '/public/assets';

        if (!is_dir($publicAssets)) {
            return;
        }

        $this->io->text('Removing public/assets to ensure fresh dev assets…');
        $this->removePath($publicAssets);
    }

    private function createConsoleProcess(
        array $arguments,
    ): Process {
        $phpBinary = PHP_BINARY;
        $consoleBin = $this->bag->get('kernel.project_dir') . '/bin/console';
        $commandLine = array_merge([$phpBinary, $consoleBin], $arguments);
        $process = new Process($commandLine, $this->bag->get('kernel.project_dir'));
        $process->setTimeout(null);

        return $process;
    }

    private function createDevAppProcess(
        array $arguments,
    ): Process {
        $phpBinary = PHP_BINARY;
        $commandLine = array_merge([$phpBinary, $this->bag->get('kernel.project_dir') . '/bin/console'], $arguments);
        $process = new Process($commandLine, $this->bag->get('kernel.project_dir'));
        $process->setTimeout(null);

        return $process;
    }

    private function ensureDevBinaries(): bool
    {
        $varDir = $this->bag->get('kernel.project_dir') . '/var';

        foreach ($this->requiredBinaries as $binaryName) {
            if (!$this->binaryRegistry->has($binaryName)) {
                $this->io->warning(sprintf('Unknown binary: %s (skipping)', $binaryName));

                continue;
            }

            try {
                $provider = $this->binaryRegistry->get($binaryName);
                $manager = $provider->createManager($varDir);
                $manager->ensureLatest([$this->io, 'text']);
            } catch (RuntimeException $exception) {
                $this->io->error(sprintf('Failed to prepare %s: %s', $binaryName, $exception->getMessage()));

                return false;
            }
        }

        return true;
    }

    private function generateIconTemplates(): bool
    {
        try {
            $this->io->text('Generating icon templates...');

            $command = $this->createConsoleProcess(['valksor:icons']);

            // Run the command synchronously and wait for completion
            $command->run();

            $exitCode = $command->getExitCode();

            if (0 === $exitCode) {
                $this->io->text('✓ Icon templates generated successfully');

                return true;
            }
            $this->io->warning(sprintf('Icon generation failed with exit code %d', $exitCode));

            return false;
        } catch (RuntimeException $exception) {
            $this->io->warning(sprintf('Failed to generate icon templates: %s', $exception->getMessage()));

            return false;
        }
    }

    /**
     * @return array<string>
     */
    private function getAvailableApps(): array
    {
        // Multi-app project: scan configured apps directory
        $appsDir = $this->bag->get('kernel.project_dir') . DIRECTORY_SEPARATOR . $this->bag->get('valksor.project.apps_dir');
        $availableApps = [];

        if (!is_dir($appsDir)) {
            return $availableApps;
        }

        $entries = scandir($appsDir, SCANDIR_SORT_NONE);

        if (false === $entries) {
            return $availableApps;
        }

        foreach ($entries as $dir) {
            if ('.' !== $dir && '..' !== $dir && is_dir($appsDir . '/' . $dir)) {
                $availableApps[] = $dir;
            }
        }

        return $availableApps;
    }

    private function removePath(
        string $path,
    ): void {
        if (is_dir($path)) {
            $entries = scandir($path, SCANDIR_SORT_NONE);

            if (false === $entries) {
                return;
            }

            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                $child = $path . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($child)) {
                    $this->removePath($child);
                } else {
                    @unlink($child);
                }
            }

            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }

    private function runServices(): int
    {
        $exitCode = Command::SUCCESS;

        while ($this->running && !$this->shouldShutdown) {
            if ($this->shouldReload || [] === $this->processes) {
                if ($this->shouldReload) {
                    $this->io->newLine();
                    $this->io->section('Reloading dev services...');
                    $this->stopAllProcesses();
                    $this->cleanPublicAssets();

                    if (!$this->ensureDevBinaries()) {
                        $exitCode = Command::FAILURE;

                        break;
                    }
                    $this->shouldReload = false;
                }

                $this->startAllProcesses();
            }

            $this->setupSignalHandlers();

            $stillRunning = false;

            foreach ($this->processes as $label => $process) {
                if ($process->isRunning()) {
                    $stillRunning = true;

                    continue;
                }

                $exitStatus = $process->getExitCode();

                if (0 !== $exitStatus && null !== $exitStatus) {
                    $this->io->error(sprintf('%s exited with status %d', $label, $exitStatus));
                    $exitCode = $exitStatus;
                    $this->shouldShutdown = true;

                    break;
                }
            }

            if (!$stillRunning && !$this->shouldReload) {
                $this->shouldShutdown = true;

                break;
            }

            usleep(200_000);
        }

        $this->io->text('Shutting down dev tooling…');
        $this->stopAllProcesses();

        $this->io->success('valksor:watch complete.');

        return $exitCode;
    }

    private function setupSignalHandlers(): void
    {
        static $handlersSet = false;

        if ($handlersSet) {
            return;
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

        $handlersSet = true;
    }

    private function startAllProcesses(): void
    {
        $this->processes = [];

        $context = new ServiceContext(
            $this->bag->get('kernel.project_dir'),
            $this->bag->get('kernel.project_dir') . '/bin/console',
            $this->getAvailableApps(),
            $this->io,
        );

        foreach ($this->requiredServices as $serviceName => $serviceConfig) {
            // Skip if service is disabled
            if (isset($serviceConfig['enabled']) && false === $serviceConfig['enabled']) {
                continue;
            }

            if (!$this->serviceRegistry->has($serviceName)) {
                $this->io->warning(sprintf('Unknown service: %s (skipping)', $serviceName));

                continue;
            }

            try {
                $provider = $this->serviceRegistry->get($serviceName);
                $serviceProcesses = $provider->startService($context);

                foreach ($serviceProcesses as $label => $config) {
                    $process = $config['process'];
                    $readySignal = $config['readySignal'] ?? null;

                    if (null !== $readySignal) {
                        // Service requires readiness check
                        $readyFlag = false;
                        $this->startProcess($label, $process, static function (string $line) use (&$readyFlag, $readySignal): void {
                            if ('' === $line) {
                                return;
                            }

                            if (str_contains($line, $readySignal)) {
                                $readyFlag = true;
                            }
                        });

                        if (!$this->awaitInitialBuild($label, $process, $readyFlag)) {
                            throw new RuntimeException(sprintf('%s failed to initialize', $label));
                        }
                    } else {
                        // Service starts immediately without readiness check
                        $this->startProcess($label, $process);
                    }

                    $this->processes[$label] = $process;
                }
            } catch (RuntimeException $exception) {
                $this->io->error(sprintf('Failed to start service %s: %s', $serviceName, $exception->getMessage()));

                throw $exception;
            }
        }
    }

    private function startProcess(
        string $label,
        Process $process,
        ?callable $lineCallback = null,
    ): void {
        $process->start(function ($type, $buffer) use ($label, $lineCallback): void {
            $prefix = sprintf('[%s] ', $label);
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $buffer));

            foreach ($lines as $line) {
                if ('' === $line) {
                    continue;
                }

                $this->io->writeln($prefix . $line);

                if (null !== $lineCallback) {
                    $lineCallback($line);
                }
            }
        });

        $this->io->text(sprintf('%s started (PID %d)', $label, $process->getPid()));
    }

    private function stopAllProcesses(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->signal(SIGTERM);
            }
        }

        foreach ($this->processes as $label => $process) {
            $process->wait();
            $this->io->text(sprintf('%s stopped.', $label));
        }

        $this->processes = [];
    }
}
