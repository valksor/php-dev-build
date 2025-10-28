<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Config\DevCommandConfig;
use ValksorDev\Build\Config\ProjectStructureConfig;
use ValksorDev\Build\Service\DevWatchService;

#[AsCommand(name: 'valksor:dev', description: 'Run SSE server and hot reload together for development.')]
final class DevCommand extends AbstractCommand
{
    public function __construct(
        ParameterBagInterface $bag,
        private readonly DevWatchService $devWatchService,
        private readonly DevCommandConfig $devCommandConfig,
        ProjectStructureConfig $projectStructure,
    ) {
        parent::__construct($bag, $projectStructure);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);

        // Kill any conflicting SSE-related processes before starting
        $this->devWatchService->killConflictingSseProcesses($io);

        $this->setServiceIo($this->devWatchService, $io);
        $this->devWatchService->writePidFile();

        try {
            // Convert service names from underscore to hyphen format for compatibility
            $services = [];

            foreach ($this->devCommandConfig->services->getEnabledServices() as $serviceName => $serviceConfig) {
                // Convert hot_reload -> hot-reload
                $convertedName = str_replace('_', '-', $serviceName);
                $services[$convertedName] = $serviceConfig;
            }

            $config = [
                'services' => $services,
                'skip_binaries' => $this->devCommandConfig->shouldSkipBinaries(),
                'skip_initialization' => $this->devCommandConfig->shouldSkipInitialization(),
                'skip_asset_cleanup' => $this->devCommandConfig->shouldSkipAssetCleanup(),
            ];

            return $this->devWatchService->start($config);
        } finally {
            $this->devWatchService->removePidFile();
        }
    }

    protected function getSseProcessesToKill(): array
    {
        return ['sse', 'hot_reload', 'dev'];
    }
}
