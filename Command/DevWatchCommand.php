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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Service\DevWatchService;

#[AsCommand(name: 'valksor:watch', description: 'Run Tailwind, importmap, and hot reload watchers in parallel.')]
final class DevWatchCommand extends AbstractCommand
{
    public function __construct(
        private readonly DevWatchService $devWatchService,
        ParameterBagInterface $bag,
    ) {
        parent::__construct($bag);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);
        $this->setServiceIo($this->devWatchService, $io);

        $this->devWatchService->createPidFilePath($this->devWatchService::getServiceName());

        // Kill any conflicting SSE-related processes before starting
        $this->devWatchService->killConflictingSseProcesses($io);

        $this->devWatchService->writePidFile();

        try {
            return $this->devWatchService->start();
        } finally {
            $this->devWatchService->removePidFile();
        }
    }

    protected function getSseProcessesToKill(): array
    {
        return ['sse', 'hot-reload', 'watch'];
    }
}
