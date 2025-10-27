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

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Interface for dev service providers.
 *
 * Each provider represents a development service (tailwind, importmap, hot-reload, etc.)
 * and knows how to start the appropriate process(es) for that service.
 */
#[AutoconfigureTag('valksor.service_provider')]
interface ProviderInterface
{
    /**
     * Get the unique name/identifier for this service.
     *
     * @return string The service name (e.g., 'tailwind', 'importmap', 'hot-reload')
     */
    public function getName(): string;

    /**
     * Start the service and return process configuration(s).
     *
     * @param ServiceContext $context Context with project info and process creation methods
     *
     * @return array<string, array{process: \Symfony\Component\Process\Process, readySignal?: string}>
     *                                                                                                 Array of [label => [process, optional readySignal]]
     *                                                                                                 - label: Display name for the process (e.g., 'tailwind', 'messenger-sentry-app1')
     *                                                                                                 - process: The started Process instance
     *                                                                                                 - readySignal: Optional string to detect when service is ready (e.g., 'Entering watch mode.')
     */
    public function startService(
        ServiceContext $context,
    ): array;
}
