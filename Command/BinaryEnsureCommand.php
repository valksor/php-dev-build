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

namespace ValksorDev\Build\Command;

use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Binary\BinaryRegistry;
use ValksorDev\Build\Provider\ProviderRegistry;

use function implode;
use function sprintf;
use function ucfirst;

#[AsCommand(name: 'valksor:binary', description: 'Ensure tool binaries/assets are downloaded (tailwindcss, esbuild, daisyui).')]
final class BinaryEnsureCommand extends AbstractCommand
{
    public function __construct(
        private readonly BinaryRegistry $binaryRegistry,
        ParameterBagInterface $bag,
        ProviderRegistry $providerRegistry,
    ) {
        parent::__construct($bag, $providerRegistry);
    }

    /**
     * Execute the binary ensure command.
     *
     * @throws JsonException
     */
    public function __invoke(
        #[Argument(
            description: 'Tool binary to download',
        )]
        string $tool,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);

        // Validate tool exists in registry
        if (!$this->binaryRegistry->has($tool)) {
            $availableBinaries = $this->binaryRegistry->getAvailableNames();

            return $this->handleCommandError(sprintf('Unsupported tool: %s. Supported tools: %s', $tool, implode(', ', $availableBinaries)), $io);
        }

        $projectRoot = $this->resolveProjectRoot();
        $manager = $this->createManagerForTool($projectRoot, $tool);

        try {
            $tag = $manager->ensureLatest([$io, 'text']);

            return $this->handleCommandSuccess(sprintf('%s assets ready (%s).', ucfirst($tool), $tag), $io);
        } catch (RuntimeException $exception) {
            return $this->handleCommandError($exception->getMessage(), $io);
        }
    }

    private function createManagerForTool(
        string $projectRoot,
        string $tool,
    ): object {
        $varDir = $projectRoot . '/var';

        return $this->binaryRegistry->get($tool)->createManager($varDir, $tool);
    }
}
