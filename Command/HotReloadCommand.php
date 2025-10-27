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

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Service\HotReloadService;

#[AsCommand(name: 'valksor:hot-reload', description: 'Run the hot reload service with file watching for development.')]
final class HotReloadCommand extends AbstractCommand
{
    public function __construct(
        private readonly HotReloadService $hotReloadService,
        ParameterBagInterface $bag,
    ) {
        parent::__construct($bag);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);

        // Kill any conflicting SSE processes before starting
        $this->killConflictingSseProcesses($io);

        $this->setServiceIo($this->hotReloadService, $io);

        $pidFile = $this->createPidFilePath('hot-reload');
        $this->hotReloadService->writePidFile($pidFile);

        try {
            $exitCode = $this->hotReloadService->start();

            $this->hotReloadService->removePidFile($pidFile);

            return $exitCode;
        } catch (Exception $e) {
            $this->hotReloadService->removePidFile($pidFile);
            $io->error('Hot reload service failed: ' . $e->getMessage());

            return 1;
        }
    }

    protected function getSseProcessesToKill(): array
    {
        return ['hot-reload'];
    }
}
