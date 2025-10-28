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

/**
 * Initialization configuration value object.
 *
 * Typed configuration for one-time initialization tasks that run at startup.
 */
readonly class InitializationConfig
{
    /**
     * @param IconsConfig $icons Icon generation configuration
     */
    public function __construct(
        public IconsConfig $icons,
    ) {
    }

    /**
     * Create from raw configuration array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(
        array $config,
    ): self {
        return new self(
            icons: IconsConfig::fromArray($config['icons'] ?? []),
        );
    }
}

/**
 * Icons initialization configuration.
 */
readonly class IconsConfig
{
    /**
     * @param bool        $enabled  Whether icon generation is enabled
     * @param string|null $target   Specific icon target to generate (null = all targets)
     * @param bool        $blocking Whether icon generation blocks startup
     */
    public function __construct(
        public bool $enabled = true,
        public ?string $target = null,
        public bool $blocking = true,
    ) {
    }

    /**
     * Check if icon generation should run asynchronously.
     */
    public function shouldRunAsynchronously(): bool
    {
        return $this->enabled && !$this->blocking;
    }

    /**
     * Check if icon generation should run synchronously.
     */
    public function shouldRunSynchronously(): bool
    {
        return $this->enabled && $this->blocking;
    }

    /**
     * Create from raw configuration array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(
        array $config,
    ): self {
        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            target: $config['target'] ?? null,
            blocking: (bool) ($config['blocking'] ?? true),
        );
    }
}
