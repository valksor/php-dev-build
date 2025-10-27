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
 * Provider for hot reload service (development only with file watching).
 */
final class HotReloadProvider implements ProviderInterface
{
    public function getName(): string
    {
        return 'hot-reload';
    }

    public function startService(
        ServiceContext $context,
    ): array {
        $process = $context->createDevAppProcess(['valksor:hot-reload']);

        return [
            'hot-reload' => [
                'process' => $process,
                // No readySignal - starts immediately
            ],
        ];
    }
}
