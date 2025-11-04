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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Provider\ProviderRegistry;
use ValksorDev\Build\Service\TailwindService;

#[AsCommand(name: 'valksor:tailwind', description: 'Build Tailwind CSS assets using the PHP tooling.')]
final class TailwindBuildCommand extends AbstractCommand
{
    public function __construct(
        ParameterBagInterface $parameterBag,
        ProviderRegistry $providerRegistry,
        private readonly TailwindService $tailwindService,
    ) {
        parent::__construct($parameterBag, $providerRegistry);
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option(
            description: 'Watch for file changes and rebuild automatically.',
            name: 'watch',
            shortcut: 'w',
        )]
        bool $watch = false,
        #[Option(description: 'Minify output regardless of BUILD_ENV.', name: 'minify', shortcut: 'm')]
        bool $minify = false,
    ): int {
        $io = $this->createSymfonyStyle($input, $output);

        $this->setServiceIo($this->tailwindService, $io);

        $appId = $input->getParameterOption(['--id', '-i']);

        if ($appId) {
            $this->tailwindService->setActiveAppId($appId);
        }

        $cleanup = $this->handleWatchMode($this->tailwindService, $input, 'tailwind');

        $exitCode = $this->tailwindService->start([
            'watch' => $watch,
            'minify' => $minify,
        ]);

        $cleanup();

        return $exitCode;
    }
}
