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
use ValksorDev\Build\Util\ConsoleCommandBuilder;

/**
 * Provider for icon generation service.
 * Generates icon templates during initialization.
 */
final class IconsProvider implements ProviderInterface
{
    public function __construct(
        private readonly ConsoleCommandBuilder $commandBuilder,
    ) {
    }

    public function build(
        array $options,
    ): int {
        // Icons are only generated during init phase
        return Command::SUCCESS;
    }

    public function getDependencies(): array
    {
        return ['binaries']; // Ensure binaries are available first
    }

    public function getName(): string
    {
        return 'icons';
    }

    public function getServiceOrder(): int
    {
        return 15; // Run after binaries but before build tools
    }

    public function init(
        array $options,
    ): void {
        // Generate icon templates
        $process = $this->commandBuilder->build('valksor:icons');
        $process->run();
    }

    public function watch(
        array $options,
    ): int {
        // Icons are only generated during init phase
        return Command::SUCCESS;
    }
}
