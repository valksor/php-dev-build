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

namespace ValksorDev\Build\Command;

use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;
use Valksor\Component\Sse\Helper;
use ValksorDev\Build\Provider\IoAwareInterface;
use ValksorDev\Build\Provider\ProviderRegistry;

abstract class AbstractCommand extends Command
{
    use Helper;

    /**
     * Shared identifier for consistency.
     */
    protected string $sharedIdentifier = 'infrastructure';

    public function __construct(
        protected readonly ParameterBagInterface $parameterBag,
        protected readonly ProviderRegistry $providerRegistry,
    ) {
        parent::__construct();
    }

    protected function addNonInteractiveOption(): void
    {
        $this->addOption('non-interactive', null, InputOption::VALUE_NONE, 'Run in non-interactive mode (no real-time output)');
    }

    protected function addWatchOption(): void
    {
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Run in watch mode');
    }

    /**
     * Create SymfonyStyle instance.
     */
    protected function createSymfonyStyle(
        InputInterface $input,
        $output,
    ): SymfonyStyle {
        return new SymfonyStyle($input, $output);
    }

    /**
     * Execute sub command.
     */
    protected function executeSubCommand(
        string $command,
        SymfonyStyle $io,
        array $arguments = [],
    ): int {
        $process = new Process(['php', 'bin/console', $command, ...$arguments]);
        $process->run();

        return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    protected function getAppsDir(): string
    {
        $appsDir = $this->parameterBag->get('valksor.project.apps_dir');

        return $this->resolveProjectRoot() . '/' . ltrim($appsDir, '/');
    }

    protected function getInfrastructureDir(): string
    {
        $infrastructureDir = $this->parameterBag->get('valksor.project.infrastructure_dir');

        return $this->resolveProjectRoot() . '/' . ltrim($infrastructureDir, '/');
    }

    /**
     * Get shared directory (infrastructure).
     */
    protected function getSharedDir(): string
    {
        return $this->getInfrastructureDir();
    }

    /**
     * Handle command error.
     */
    protected function handleCommandError(
        string $message,
        ?SymfonyStyle $io = null,
    ): int {
        if ($io) {
            $io->error($message);
        }

        return Command::FAILURE;
    }

    /**
     * Handle command success.
     */
    protected function handleCommandSuccess(
        string $message = 'Command completed successfully!',
        ?SymfonyStyle $io = null,
    ): int {
        if ($io) {
            $io->success($message);
        }

        return Command::SUCCESS;
    }

    /**
     * Handle watch mode setup and cleanup for services.
     */
    protected function handleWatchMode(
        object $service,
        InputInterface $input,
        string $serviceName,
    ): callable {
        if (!$this->isWatchMode($input)) {
            // Return a no-op cleanup function for non-watch mode
            return function (): void {
                // No cleanup needed for non-watch mode
            };
        }

        // For watch mode, set up cleanup that will stop the service
        return function () use ($service): void {
            if (method_exists($service, 'stop')) {
                $service->stop();
            }

            // Clean up PID files if the service supports it
            if (method_exists($service, 'removePidFile')) {
                $service->removePidFile();
            }
        };
    }

    protected function isNonInteractive(
        InputInterface $input,
    ): bool {
        return (bool) $input->getOption('non-interactive');
    }

    protected function isProductionEnvironment(): bool
    {
        return 'prod' === ($_ENV['APP_ENV'] ?? 'dev');
    }

    protected function isWatchMode(
        InputInterface $input,
    ): bool {
        return (bool) $input->getOption('watch');
    }

    protected function resolveProjectRoot(): string
    {
        return $this->parameterBag->get('kernel.project_dir');
    }

    /**
     * Run init phase - always runs first for all commands.
     */
    protected function runInit(
        SymfonyStyle $io,
    ): void {
        $servicesConfig = $this->parameterBag->get('valksor.build.services', []);
        $initProviders = $this->providerRegistry->getProvidersByFlag($servicesConfig, 'init');

        if (empty($initProviders)) {
            return;
        }

        $io->section('Running initialization tasks...');

        // Binaries always run first
        if (isset($initProviders['binaries'])) {
            $io->text('Ensuring binaries are available...');
            $this->runProvider('binaries', $initProviders['binaries'], []);
            unset($initProviders['binaries']);
        }

        // Run remaining init providers
        foreach ($initProviders as $name => $provider) {
            $config = $servicesConfig[$name] ?? [];
            $options = $config['options'] ?? [];
            $this->runProvider($name, $provider, $options);
        }

        $io->success('Initialization completed');
    }

    /**
     * Run a single provider with error handling.
     */
    protected function runProvider(
        string $name,
        $provider,
        array $options,
    ): void {
        try {
            $provider->init($options);
        } catch (Exception $e) {
            // In development, warn but continue; in production, fail
            if ($this->isProductionEnvironment()) {
                throw new RuntimeException("Provider '{$name}' failed: " . $e->getMessage(), 0, $e);
            }
            // Warning - continue but this could be problematic in non-interactive mode
            // TODO: Consider passing SymfonyStyle instance for proper warning display
        }
    }

    /**
     * Get SSE command for integration.
     */
    protected function runSseCommand(): int
    {
        $process = new Process(['php', 'bin/console', 'valksor:sse']);

        // Start SSE server in background (non-blocking)
        $process->start();

        // Give SSE server more time to start and bind to port
        $maxWaitTime = 3; // 3 seconds max wait time
        $waitInterval = 250000; // 250ms intervals
        $elapsedTime = 0;

        while ($elapsedTime < $maxWaitTime) {
            usleep($waitInterval);
            $elapsedTime += ($waitInterval / 1000000);

            // Check if process is still running and hasn't failed
            if (!$process->isRunning()) {
                // Process stopped - check if it was successful
                return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
            }

            // After 1 second, check if we can verify the server is actually stable
            if ($elapsedTime >= 1.0) {
                // The SSE server should be stable by now, proceed
                break;
            }
        }

        // Final check - is the process still running successfully?
        return $process->isRunning() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Set SymfonyStyle on provider objects that support it.
     */
    protected function setProviderIo(
        object $provider,
        SymfonyStyle $io,
    ): void {
        if ($provider instanceof IoAwareInterface) {
            $provider->setIo($io);
        }
    }

    /**
     * Set SymfonyStyle on service objects that support it.
     */
    protected function setServiceIo(
        object $service,
        SymfonyStyle $io,
    ): void {
        if (method_exists($service, 'setIo')) {
            $service->setIo($io);
        }
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

        return $this->isProductionEnvironment();
    }

    protected function shouldShowRealTimeOutput(
        InputInterface $input,
    ): bool {
        return !$this->isNonInteractive($input);
    }
}
