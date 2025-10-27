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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Valksor\Component\Sse\Command\AbstractCommand as SseAbstractCommand;

abstract class AbstractCommand extends SseAbstractCommand
{
    protected function addWatchOption(): void
    {
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Run in watch mode');
    }

    protected function handleWatchMode(
        object $service,
        InputInterface $input,
        string $serviceName,
    ): callable {
        if ($this->isWatchMode($input)) {
            $this->writePidFile($service, $serviceName);

            return function () use ($service, $serviceName): void {
                $this->removePidFile($service, $serviceName);
            };
        }

        return function (): void {}; // No-op for non-watch mode
    }

    protected function isProductionEnvironment(): bool
    {
        return 'prod' === $this->p('build.env');
    }

    protected function isWatchMode(
        InputInterface $input,
    ): bool {
        return (bool) $input->getOption('watch');
    }

    protected function shouldMinify(
        InputInterface $input,
    ): bool {
        if ($input->hasOption('no-minify') && $input->getOption('no-minify')) {
            return false;
        }

        if ($input->hasOption('minify') && $input->getOption('minify')) {
            return true;
        }

        return false !== $this->p('build.minify') && 'dev' !== $this->p('build.env');
    }
}
