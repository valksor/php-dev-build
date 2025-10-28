<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\BuildStep;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use ValksorDev\Build\Context\ExecutionContext;
use ValksorDev\Build\Config\ServiceConfig;
use ValksorDev\Build\Provider\ProviderInterface;
use ValksorDev\Build\Provider\ServiceContext;

/**
 * Provider for Symfony assets build step.
 */
#[AutoconfigureTag('valksor.service_provider')]
final class SymfonyAssetsBuildStepProvider implements ProviderInterface
{
    public function executeBuildStep(
        ExecutionContext $context,
    ): int {
        $context->io->text('Compiling Symfony assets...');

        // Run importmap:install
        $returnCode = $context->executeSubCommand('importmap:install', ['--no-interaction' => true]);

        if (Command::SUCCESS !== $returnCode) {
            $context->io->error('importmap:install failed.');

            return Command::FAILURE;
        }

        // Run asset-map:compile
        $returnCode = $context->executeSubCommand('asset-map:compile', ['--no-interaction' => true]);

        if (Command::SUCCESS !== $returnCode) {
            $context->io->error('asset-map:compile failed.');

            return Command::FAILURE;
        }

        $context->io->text('✓ Symfony assets compiled successfully');

        return Command::SUCCESS;
    }

    public function getName(): string
    {
        return 'symfony_assets';
    }

    public function startService(
        ServiceContext $context,
    ): array {
        // Symfony assets don't have a watch mode, so return an empty array
        // This provider is only used in build mode
        return [];
    }
}
