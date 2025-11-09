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
use ValksorDev\Build\Service\ProcessManager;
use ValksorDev\Build\Util\ConsoleCommandBuilder;

/**
 * Provider for hot reload service (development only with file watching).
 */
final class HotReloadProvider implements ProviderInterface
{
    public function __construct(
        private readonly ConsoleCommandBuilder $commandBuilder,
        private readonly ProcessManager $processManager,
    ) {
    }

    public function build(
        array $options,
    ): int {
        // Hot reload is development only, nothing to build
        return Command::SUCCESS;
    }

    public function getDependencies(): array
    {
        return []; // No dependencies
    }

    public function getName(): string
    {
        return 'hot_reload';
    }

    public function getServiceOrder(): int
    {
        return 30; // Run after binaries and tailwind
    }

    public function init(
        array $options,
    ): void {
        // Hot reload doesn't need initialization
    }

    public function watch(
        array $options,
    ): int {
        // Hot reload command now gets configuration directly from the service
        // No need to pass command-line options - configuration is handled internally
        $arguments = $this->commandBuilder->buildArguments('valksor:hot-reload');
        $isInteractive = $options['interactive'] ?? true;

        return $this->processManager->executeProcess($arguments, $isInteractive, 'Hot reload service');
    }
}
