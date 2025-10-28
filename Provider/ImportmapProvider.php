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

/**
 * Provider for Importmap sync watcher service.
 */
final class ImportmapProvider implements ProviderInterface
{
    public function getName(): string
    {
        return 'importmap';
    }

    public function startService(
        ServiceContext $context,
    ): array {
        $process = $context->createDevAppProcess(['valksor:importmap', '--watch']);

        return [
            'importmap' => [
                'process' => $process,
                'readySignal' => 'Entering importmap watch mode.',
            ],
        ];
    }
}
