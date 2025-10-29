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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

#[AsCommand(name: 'valksor-prod:build', description: 'Build all production assets using the new flag-based service system.')]
final class ProdBuildCommand extends AbstractCommand
{
    public function __construct(
        \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface $bag,
        \ValksorDev\Build\Provider\ProviderRegistry $providerRegistry,
    ) {
        parent::__construct($bag, $providerRegistry);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $io->title('Production Build');

        // Get services configuration from ParameterBag
        $servicesConfig = $this->parameterBag->get('valksor.build.services', []);

        // Run init phase first
        $this->runInit($io);

        // Get providers that should run in production (prod=true)
        $prodProviders = $this->providerRegistry->getProvidersByFlag($servicesConfig, 'prod');

        if (empty($prodProviders)) {
            $io->warning('No production services are enabled in configuration.');

            return Command::SUCCESS;
        }

        // Validate all configured providers exist
        $missingProviders = $this->providerRegistry->validateProviders($servicesConfig);

        if (!empty($missingProviders)) {
            $io->error(sprintf('Missing providers for: %s', implode(', ', $missingProviders)));

            return Command::FAILURE;
        }

        $stepCount = count($prodProviders);
        $currentStep = 1;

        $io->text(sprintf('Running %d production service(s)...', $stepCount));
        $io->newLine();

        foreach ($prodProviders as $name => $provider) {
            $io->section(sprintf('Step %d/%d: %s', $currentStep, $stepCount, ucfirst($name)));

            $config = $servicesConfig[$name] ?? [];
            $options = $config['options'] ?? [];

            // Force production environment for all providers
            $options['environment'] = 'prod';

            try {
                $result = $provider->build($options);

                if (Command::SUCCESS !== $result) {
                    $io->error(sprintf('Service "%s" failed with exit code %d', $name, $result));

                    return Command::FAILURE;
                }

                $io->success(sprintf('✓ %s completed successfully', ucfirst($name)));
            } catch (Exception $e) {
                $io->error(sprintf('Service "%s" failed: %s', $name, $e->getMessage()));

                return Command::FAILURE;
            }

            $currentStep++;
        }

        $io->success('Production build completed successfully!');

        return Command::SUCCESS;
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
            // In production, fail fast
            throw new RuntimeException("Provider '{$name}' failed: " . $e->getMessage(), 0, $e);
        }
    }
}
