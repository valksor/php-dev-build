<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Config\BuildStepConfig;
use ValksorDev\Build\Config\ConfigurationFactory;
use ValksorDev\Build\Config\ProjectStructureConfig;
use ValksorDev\Build\Config\ProdBuildConfig;
use ValksorDev\Build\Context\ExecutionContext;
use ValksorDev\Build\Provider\ProviderRegistry;

use function count;
use function sprintf;

#[AsCommand(name: 'valksor-prod:build', description: 'Build all production assets (binaries, Tailwind, importmap, icons, Symfony assets).')]
final class ProdBuildCommand extends AbstractCommand
{
    private SymfonyStyle $io;
    private OutputInterface $output;

    public function __construct(
        ParameterBagInterface $bag,
        private readonly ProviderRegistry $providerRegistry,
        ProjectStructureConfig $projectStructure,
    ) {
        parent::__construct($bag, $projectStructure);
        $this->parameterBag = $bag;
    }

    /**
     * Public wrapper for executeSubCommand to allow access from BuildStepContext.
     */
    public function executeSubCommandForBuildStep(
        string $command,
        array $arguments = [],
    ): int {
        return $this->executeSubCommand($command, $this->io, $arguments);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->io = $this->createSymfonyStyle($input, $output);
        $this->output = $output;

        $this->io->title('Production Build');

        // Get config from ConfigurationFactory
        $configFactory = new ConfigurationFactory($this->parameterBag);
        $prodBuildConfig = $configFactory->createProdBuildConfig();
        $enabledSteps = $prodBuildConfig->getEnabledStepNames();

        if (empty($enabledSteps)) {
            $this->io->warning('No build steps are enabled in configuration.');

            return Command::SUCCESS;
        }

        $stepCount = count($enabledSteps);
        $currentStep = 1;

        $this->io->text(sprintf('Running %d enabled build step(s)...', $stepCount));
        $this->io->newLine();

        foreach ($enabledSteps as $stepName) {
            $this->io->section(sprintf('Step %d/%d: %s', $currentStep, $stepCount, ucfirst($stepName)));

            $stepConfigObj = $prodBuildConfig->getStep($stepName);
            $stepConfigArray = [
                'enabled' => $stepConfigObj->enabled,
                'options' => $stepConfigObj->options,
            ];
            $result = $this->executeBuildStep($stepName, $stepConfigArray);

            if (Command::SUCCESS !== $result) {
                return $this->handleCommandError(sprintf('Build step "%s" failed.', $stepName), $this->io);
            }

            $currentStep++;
        }

        return $this->handleCommandSuccess('Production build completed successfully!', $this->io);
    }

    private function executeBuildStep(
        string $stepName,
        array $stepConfig,
    ): int {
        if (!$this->providerRegistry->has($stepName)) {
            return $this->handleCommandError(sprintf('Unknown build step: %s', $stepName), $this->io);
        }

        $provider = $this->providerRegistry->get($stepName);

        // Ensure the provider supports build steps
        if (!method_exists($provider, 'executeBuildStep')) {
            return $this->handleCommandError(sprintf('Provider "%s" does not support build steps', $stepName), $this->io);
        }

        // Convert array config to BuildStepConfig object
        $buildStepConfig = new BuildStepConfig(
            enabled: $stepConfig['enabled'] ?? true,
            options: $stepConfig['options'] ?? [],
        );

        // Create context for the build step
        $context = ExecutionContext::forBuild(
            projectRoot: $this->resolveProjectRoot(),
            io: $this->io,
            stepConfig: $buildStepConfig,
            executeSubCommand: [$this, 'executeSubCommandForBuildStep'],
        );

        return $provider->executeBuildStep($context);
    }
}
