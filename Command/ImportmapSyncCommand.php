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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Provider\ProviderRegistry;
use ValksorDev\Build\Service\ImportmapService;

#[AsCommand(name: 'valksor:importmap', description: 'Mirror JavaScript assets into dist directories for importmap usage.')]
final class ImportmapSyncCommand extends AbstractCommand
{
    public function __construct(
        ParameterBagInterface $parameterBag,
        ProviderRegistry $providerRegistry,
        private readonly ImportmapService $importmapService,
    ) {
        parent::__construct($parameterBag, $providerRegistry);
    }

    protected function configure(): void
    {
        $this
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for file changes and keep dist directories in sync.')
            ->addOption('minify', 'm', InputOption::VALUE_NONE, 'Minify output regardless of BUILD_ENV or BUILD_MINIFY.');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);

        $watch = $this->isWatchMode($input);
        $minify = $this->shouldMinify($input);

        $this->setServiceIo($this->importmapService, $io);

        $cleanup = $this->handleWatchMode($this->importmapService, $input, 'importmap');

        $exitCode = $this->importmapService->start([
            'watch' => $watch,
            'minify' => $minify,
        ]);

        $cleanup();

        return $exitCode;
    }
}
