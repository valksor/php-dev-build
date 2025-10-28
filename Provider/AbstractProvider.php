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

namespace ValksorDev\Build\Provider;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;

abstract class AbstractProvider implements ProviderInterface
{
    protected string $projectDir;

    public function __construct(
        protected ParameterBagInterface $bag,
    ) {
        $this->projectDir = $bag->get('kernel.project_dir');
    }

    public function createConsoleProcess(
        array $arguments,
    ): Process {
        $command = [$this->getConsolePath(), ...$arguments];

        return new Process($command);
    }

    public function dev(): array
    {
        return [];
    }

    public function devCommand(): array
    {
        return [];
    }

    public function getConsolePath(): string
    {
        return $this->projectDir . '/bin/console';
    }

    public function init(): int
    {
        return Command::SUCCESS;
    }

    public function prod(): int
    {
        return Command::SUCCESS;
    }
}
