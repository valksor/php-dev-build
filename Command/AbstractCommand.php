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
use Valksor\Bundle\Command\AbstractCommand as BundleAbstractCommand;
use Valksor\Component\Sse\Helper;
use ValksorDev\Build\Provider\IoAwareInterface;
use ValksorDev\Build\Provider\ProviderRegistry;

use function method_exists;
use function usleep;

abstract class AbstractCommand extends BundleAbstractCommand
{
    use Helper;

    public function __construct(
        ParameterBagInterface $parameterBag,
        protected readonly ProviderRegistry $providerRegistry,
    ) {
        parent::__construct($parameterBag);
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
        return static function () use ($service): void {
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

    protected function isWatchMode(
        InputInterface $input,
    ): bool {
        return (bool) $input->getOption('watch');
    }

    /**
     * Run init phase - always runs first for all commands.
     */
    protected function runInit(
        SymfonyStyle $io,
    ): void {
        $servicesConfig = $this->parameterBag->get('valksor.build.services');
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
                throw new RuntimeException("Provider '$name' failed: " . $e->getMessage(), 0, $e);
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
