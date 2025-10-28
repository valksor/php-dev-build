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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Context\ExecutionContext;
use ValksorDev\Build\Config\ServiceConfig;
use ValksorDev\Build\Provider\ProviderInterface;
use ValksorDev\Build\Provider\ServiceContext;

use function sprintf;

/**
 * Provider for Binaries build step.
 */
#[AutoconfigureTag('valksor.service_provider')]
final class BinariesBuildStepProvider implements ProviderInterface
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function executeBuildStep(
        ExecutionContext $context,
    ): int {
        $context->io->text('Ensuring all required binaries...');

        $binaries = $this->parameterBag->get('valksor.build.binaries', []);

        foreach ($binaries as $binary) {
            $context->io->text(sprintf('• Ensuring %s...', $binary));

            $returnCode = $context->executeSubCommand('valksor:binary', ['tool' => $binary]);

            if (Command::SUCCESS !== $returnCode) {
                $context->io->error(sprintf('Failed to ensure %s binary.', $binary));

                return Command::FAILURE;
            }
        }

        $context->io->text('✓ All required binaries ensured');

        return Command::SUCCESS;
    }

    public function getName(): string
    {
        return 'binaries';
    }

    public function startService(
        ServiceContext $context,
    ): array {
        // Binaries don't have a watch mode, so return an empty array
        // This provider is only used in build mode
        return [];
    }
}
