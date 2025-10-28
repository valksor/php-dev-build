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
 * Provider for Icon generation build step.
 */
#[AutoconfigureTag('valksor.service_provider')]
final class IconsBuildStepProvider implements ProviderInterface
{
    public function executeBuildStep(
        ExecutionContext $context,
    ): int {
        $context->io->text('Generating icon templates...');

        $arguments = [];

        if ($context->hasOption('target')) {
            $arguments[] = $context->getOption('target');
        }

        $returnCode = $context->executeSubCommand('valksor:icons', $arguments);

        if (Command::SUCCESS !== $returnCode) {
            $context->io->error('Icon generation failed.');

            return Command::FAILURE;
        }

        $context->io->text('✓ Icon templates generated successfully');

        return Command::SUCCESS;
    }

    public function getName(): string
    {
        return 'icons';
    }

    public function startService(
        ServiceContext $context,
    ): array {
        // Icons don't have a watch mode, so return an empty array
        // This provider is only used in build mode
        return [];
    }
}
