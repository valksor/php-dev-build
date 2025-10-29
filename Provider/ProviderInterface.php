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

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Unified interface for all service providers.
 *
 * Each provider represents a service (binaries, icons, tailwind, importmap, hot-reload, etc.)
 * and supports three execution phases: init, build, and watch.
 */
#[AutoconfigureTag('valksor.service_provider')]
interface ProviderInterface
{
    /**
     * Production build phase.
     * Used for building assets, minifying files, etc.
     *
     * @param array $options Service-specific options from configuration
     *
     * @return int Exit code (0 for success, non-zero for error)
     */
    public function build(
        array $options,
    ): int;

    /**
     * Services that must run before this one.
     * Used for dependency resolution.
     *
     * @return array Array of service names that must run first
     */
    public function getDependencies(): array;

    /**
     * Get the unique name/identifier for this provider.
     *
     * @return string The provider name (e.g., 'tailwind', 'importmap', 'hot_reload')
     */
    public function getName(): string;

    /**
     * Service execution order (lower numbers run first).
     * Used for dependency resolution and predictable execution.
     *
     * @return int Execution order (lower = earlier)
     */
    public function getServiceOrder(): int;

    /**
     * Initialize phase - runs before all other phases.
     * Used for one-time setup like downloading binaries, generating templates, etc.
     *
     * @param array $options Service-specific options from configuration
     */
    public function init(
        array $options,
    ): void;

    /**
     * Development/watch phase.
     * Used for starting long-running development processes with file watching.
     *
     * @param array $options Service-specific options from configuration
     *
     * @return int Exit code (0 for success, non-zero for error)
     */
    public function watch(
        array $options,
    ): int;
}
