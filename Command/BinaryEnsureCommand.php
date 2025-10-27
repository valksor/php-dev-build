<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s)
 * (c) SIA Valksor <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Binary\BinaryRegistry;

use function implode;
use function sprintf;
use function ucfirst;

#[AsCommand(name: 'valksor:binary', description: 'Ensure tool binaries/assets are downloaded (tailwindcss, esbuild, daisyui).')]
final class BinaryEnsureCommand extends AbstractCommand
{
    public function __construct(
        #[Autowire(
            param: 'valksor.build.binaries',
        )]
        private readonly array $availableBinaries,
        private readonly BinaryRegistry $binaryRegistry,
        ParameterBagInterface $bag,
    ) {
        parent::__construct($bag);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tool', InputArgument::REQUIRED, sprintf('Tool to download (%s)', implode(', ', $this->availableBinaries)));
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);
        $tool = (string) $input->getArgument('tool');

        // Validate tool exists in registry
        if (!$this->binaryRegistry->has($tool)) {
            return $this->handleCommandError(sprintf('Unsupported tool: %s. Supported tools: %s', $tool, implode(', ', $this->availableBinaries)), $io);
        }

        $projectRoot = $this->bag->get('kernel.project_dir');
        $manager = $this->createManagerForTool($projectRoot, $tool);

        try {
            $tag = $manager->ensureLatest([$io, 'text']);
        } catch (RuntimeException $exception) {
            return $this->handleCommandError($exception->getMessage(), $io);
        }

        return $this->handleCommandSuccess(sprintf('%s assets ready (%s).', ucfirst($tool), $tag), $io);
    }

    private function createManagerForTool(
        string $projectRoot,
        string $tool,
    ): object {
        $varDir = $projectRoot . '/var';

        return $this->binaryRegistry->get($tool)->createManager($varDir);
    }
}
