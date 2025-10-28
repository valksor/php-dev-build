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
use ValksorDev\Build\Context\ExecutionContext;

/**
 * Provider for Tailwind CSS watcher service.
 */
final class TailwindProvider implements ProviderInterface
{
    public function getName(): string
    {
        return 'tailwind';
    }

    public function startService(
        ServiceContext $context,
    ): array {
        $process = $context->createDevAppProcess(['valksor:tailwind', '--watch']);

        return [
            'tailwind' => [
                'process' => $process,
                'readySignal' => 'Entering watch mode.',
            ],
        ];
    }

    public function executeBuildStep(
        ExecutionContext $context,
    ): int {
        $context->io->text('Building Tailwind CSS assets...');

        $arguments = [];

        // Add minification option if configured
        if ($context->hasOption('minify') && $context->getOption('minify')) {
            $arguments[] = '--minify';
        }

        // Add app targeting if configured
        if ($context->hasOption('app') && $context->getOption('app')) {
            $arguments[] = $context->getOption('app');
        }

        $returnCode = $context->executeSubCommand('valksor:tailwind', $arguments);

        if (Command::SUCCESS !== $returnCode) {
            $context->io->error('Tailwind CSS build failed.');

            return Command::FAILURE;
        }

        $context->io->text('✓ Tailwind CSS assets built successfully');

        return Command::SUCCESS;
    }
}
