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
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ValksorDev\Build\Service\DevService;

#[AsCommand(name: 'valksor:dev', description: 'Run lightweight development mode (SSE + hot reload).')]
final class DevCommand extends AbstractCommand
{
    public function __construct(
        private readonly DevService $devService,
    ) {
        parent::__construct(
            $devService->getParameterBag(),
            $devService->getProviderRegistry(),
        );
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option(
            description: 'Run in non-interactive mode (no real-time output)',
            name: 'non-interactive',
        )]
        bool $nonInteractive = false,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);
        $isInteractive = !$nonInteractive;

        // Set IO and interactive mode on the service
        $this->devService->setIo($io);
        $this->devService->setInteractive($isInteractive);

        // Start the dev service (handles PID management, signal handling, etc.)
        return $this->devService->start();
    }
}
