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

namespace ValksorDev\Build\Provider;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;
use ValksorDev\Build\Service\ProcessManager;

use function array_filter;
use function is_dir;
use function scandir;

use const SCANDIR_SORT_ASCENDING;

/**
 * Provider for Tailwind CSS service.
 */
final class TailwindProvider implements ProviderInterface, IoAwareInterface
{
    private ?SymfonyStyle $io = null;

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function build(
        array $options,
    ): int {
        // In production mode, build all apps
        // Get available apps from the project
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        $appsDir = $projectDir . '/' . $this->parameterBag->get('valksor.project.apps_dir');

        // Check if we should force minification in production
        $isProductionEnvironment = ($options['environment'] ?? 'dev') === 'prod';
        $shouldMinify = $isProductionEnvironment || ($options['minify'] ?? false);

        if (is_dir($appsDir)) {
            $apps = array_filter(scandir($appsDir, SCANDIR_SORT_ASCENDING), static function ($item) use ($appsDir) {
                $appPath = $appsDir . '/' . $item;

                return '.' !== $item && '..' !== $item && is_dir($appPath);
            });

            foreach ($apps as $app) {
                $arguments = ['valksor:tailwind', '--id', $app];

                // Add minification automatically in production or if explicitly requested
                if ($shouldMinify) {
                    $arguments[] = '--minify';
                }

                $process = new Process(['php', 'bin/console', ...$arguments]);
                $process->run();

                if (!$process->isSuccessful()) {
                    // Log error output for debugging
                    $this->io?->error('Tailwind provider failed for app ' . $app . ': ' . $process->getErrorOutput());

                    return Command::FAILURE;
                }
            }
        } else {
            // Fallback to running without app ID if no apps directory
            $arguments = ['valksor:tailwind'];

            // Add minification automatically in production or if explicitly requested
            if ($shouldMinify) {
                $arguments[] = '--minify';
            }

            $process = new Process(['php', 'bin/console', ...$arguments]);
            $process->run();

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    public function getDependencies(): array
    {
        return ['binaries']; // Ensure binaries run first
    }

    public function getName(): string
    {
        return 'tailwind';
    }

    public function getServiceOrder(): int
    {
        return 20; // Run after binaries (10) but before others
    }

    public function init(
        array $options,
    ): void {
        // Tailwind doesn't need initialization
    }

    public function setIo(
        SymfonyStyle $io,
    ): void {
        $this->io = $io;
    }

    public function watch(
        array $options,
    ): int {
        $arguments = ['valksor:tailwind', '--watch'];
        $isInteractive = $options['interactive'] ?? true;

        return ProcessManager::executeProcess($arguments, $isInteractive, 'Tailwind service');
    }
}
