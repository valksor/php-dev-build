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
use Symfony\Component\DependencyInjection\Attribute\AutowireTag;
use Symfony\Component\Process\Process;

/**
 * Provider for Symfony assets build steps.
 */
#[AutowireTag('valksor.service_provider')]
final class AssetsProvider implements ProviderInterface, IoAwareInterface
{
    private ?SymfonyStyle $io = null;

    public function build(
        array $options,
    ): int {
        // Step 1: Install bundle web assets (relative links)
        $process1 = new Process(['php', 'bin/console', 'assets:install', '--relative', '--no-interaction']);
        $process1->run();

        if (!$process1->isSuccessful()) {
            if ($this->io) {
                $this->io->error('[ASSETS] assets:install failed: ' . trim($process1->getErrorOutput()));
            }

            return Command::FAILURE;
        }

        // Step 2: Download/import JavaScript dependencies
        $process2 = new Process(['php', 'bin/console', 'importmap:install', '--no-interaction']);
        $process2->run();

        if (!$process2->isSuccessful()) {
            if ($this->io) {
                $this->io->error('[IMPORTMAP] importmap:install failed: ' . trim($process2->getErrorOutput()));
            }

            return Command::FAILURE;
        }

        // Step 3: Compile all mapped assets to final output directory
        $process3 = new Process(['php', 'bin/console', 'asset-map:compile', '--no-interaction']);
        $process3->run();

        if (!$process3->isSuccessful()) {
            if ($this->io) {
                $this->io->error('[ASSET-MAP] asset-map:compile failed: ' . trim($process3->getErrorOutput()));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    public function getDependencies(): array
    {
        return []; // No dependencies, should run first
    }

    public function getName(): string
    {
        return 'assets';
    }

    public function getServiceOrder(): int
    {
        return 5; // Run very early, before other build steps
    }

    public function init(
        array $options,
    ): void {
        // Assets don't need initialization, only build
    }

    public function setIo(
        SymfonyStyle $io,
    ): void {
        $this->io = $io;
    }

    public function watch(
        array $options,
    ): int {
        // Assets don't have a watch mode, they're build-only
        return Command::SUCCESS;
    }
}
