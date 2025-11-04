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
use ValksorDev\Build\Service\ProcessManager;

use function array_filter;
use function is_dir;
use function scandir;

/**
 * Provider for Importmap service.
 */
final class ImportmapProvider implements ProviderInterface, IoAwareInterface
{
    private ?SymfonyStyle $io = null;

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function build(
        array $options,
    ): int {
        // Check if we should force minification in production
        $isProductionEnvironment = ($options['environment'] ?? 'dev') === 'prod';
        $shouldMinify = $isProductionEnvironment || ($options['minify'] ?? false);

        // Get available apps from the project
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        $appsDir = $projectDir . '/' . $this->parameterBag->get('valksor.project.apps_dir');

        if (is_dir($appsDir)) {
            $apps = array_filter(scandir($appsDir), function ($item) use ($appsDir) {
                $appPath = $appsDir . '/' . $item;

                return '.' !== $item && '..' !== $item && is_dir($appPath);
            });

            foreach ($apps as $app) {
                $arguments = ['valksor:importmap', '--id', $app];

                // Add minification automatically in production or if explicitly requested
                if ($shouldMinify) {
                    $arguments[] = '--minify';
                }

                $exitCode = ProcessManager::executeProcess($arguments, false, 'Importmap build for app ' . $app);

                if (Command::SUCCESS !== $exitCode) {
                    return Command::FAILURE;
                }
            }
        } else {
            // Fallback to running without app ID if no apps directory
            $arguments = ['valksor:importmap'];

            // Add minification automatically in production or if explicitly requested
            if ($shouldMinify) {
                $arguments[] = '--minify';
            }

            return ProcessManager::executeProcess($arguments, false, 'Importmap build');
        }

        return Command::SUCCESS;
    }

    public function getDependencies(): array
    {
        return ['binaries']; // Ensure binaries run first
    }

    public function getName(): string
    {
        return 'importmap';
    }

    public function getServiceOrder(): int
    {
        return 25; // Run after binaries and tailwind, before hot_reload
    }

    public function init(
        array $options,
    ): void {
        // Importmap doesn't need initialization
    }

    public function setIo(
        SymfonyStyle $io,
    ): void {
        $this->io = $io;
    }

    public function watch(
        array $options,
    ): int {
        $arguments = ['valksor:importmap', '--watch'];
        $isInteractive = $options['interactive'] ?? true;

        return ProcessManager::executeProcess($arguments, $isInteractive, 'Importmap service');
    }
}
