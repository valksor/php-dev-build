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

namespace ValksorDev\Build\Provider;

/**
 * Provider for Tailwind CSS watcher service.
 */
final class TailwindProvider implements ProviderInterface
{
    public function getName(): string
    {
        return 'tailwind';
    }

    public function startService(
        ServiceContext $context,
    ): array {
        $process = $context->createDevAppProcess(['valksor:tailwind', '--watch']);

        return [
            'tailwind' => [
                'process' => $process,
                'readySignal' => 'Entering watch mode.',
            ],
        ];
    }
}
