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
use ValksorDev\Build\Service\DevWatchService;

#[AsCommand(name: 'valksor:watch', description: 'Run all development services (Tailwind, importmap, hot reload) in parallel.')]
final class DevWatchCommand extends AbstractCommand
{
    public function __construct(
        private readonly DevWatchService $devWatchService,
    ) {
        parent::__construct(
            $devWatchService->getParameterBag(),
            $devWatchService->getProviderRegistry(),
        );
    }

    protected function configure(): void
    {
        $this->addNonInteractiveOption();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);
        $isInteractive = $this->shouldShowRealTimeOutput($input);

        // Set IO and interactive mode on the service
        $this->devWatchService->setIo($io);
        $this->devWatchService->setInteractive($isInteractive);

        // Start the dev watch service (handles PID management, signal handling, etc.)
        return $this->devWatchService->start();
    }
}
