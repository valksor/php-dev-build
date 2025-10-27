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
 * Provider for sse SSE server service.
 */
final class SseProvider implements ProviderInterface
{
    public function getName(): string
    {
        return 'sse';
    }

    public function startService(
        ServiceContext $context,
    ): array {
        $process = $context->createDevAppProcess(['valksor:sse']);

        return [
            'sse' => [
                'process' => $process,
                // No readySignal - starts immediately
            ],
        ];
    }
}
