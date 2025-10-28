<?php declare(strict_types = 1);

/*
 * This file is part of the Valksor package.
 *
 * (c) Dāvis Zālītis (k0d3r1s) <packages@valksor.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ValksorDev\Build\Config;

use function array_key_exists;

/**
 * Individual build step configuration.
 */
readonly class BuildStepConfig
{
    /**
     * @param bool                $enabled Whether the build step is enabled
     * @param array<string,mixed> $options Step-specific options
     */
    public function __construct(
        public bool $enabled = true,
        public array $options = [],
    ) {
    }

    /**
     * Get a specific option value.
     */
    public function getOption(
        string $key,
        mixed $default = null,
    ): mixed {
        return $this->options[$key] ?? $default;
    }

    /**
     * Check if an option exists.
     */
    public function hasOption(
        string $key,
    ): bool {
        return array_key_exists($key, $this->options);
    }
}
