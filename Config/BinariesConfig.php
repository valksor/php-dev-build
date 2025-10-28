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

use function array_filter;
use function array_values;
use function in_array;
use function is_string;

/**
 * Binaries configuration value object.
 *
 * Typed configuration for required development binaries.
 */
readonly class BinariesConfig
{
    /**
     * @param array<string> $binaries List of required binary names
     */
    public function __construct(
        public array $binaries,
    ) {
        $this->validate();
    }

    /**
     * Get all required binary names.
     *
     * @return array<string>
     */
    public function getBinaries(): array
    {
        return $this->binaries;
    }

    /**
     * Check if any binaries are required.
     */
    public function hasBinaries(): bool
    {
        return !empty($this->binaries);
    }

    /**
     * Check if a binary is required.
     */
    public function hasBinary(
        string $binaryName,
    ): bool {
        return in_array($binaryName, $this->binaries, true);
    }

    /**
     * Create from raw configuration array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(
        array $config,
    ): self {
        // Handle both indexed arrays and associative arrays
        $binaries = [];

        if (isset($config[0])) {
            // Indexed array: ['tailwindcss', 'esbuild', 'daisyui', 'lucide']
            foreach ($config as $binary) {
                if (is_string($binary)) {
                    $binaries[] = $binary;
                }
            }
        } else {
            // Associative array or mixed format
            foreach ($config as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $binaries[] = $value;
                } elseif (is_string($value)) {
                    $binaries[] = $value;
                }
            }
        }

        return new self(
            binaries: array_values(array_filter($binaries)),
        );
    }

    /**
     * Get default binaries configuration.
     */
    public static function getDefault(): self
    {
        return new self([
            'tailwindcss',
            'esbuild',
            'daisyui',
            'lucide',
        ]);
    }

    private function validate(): void
    {
        foreach ($this->binaries as $binary) {
            if (!is_string($binary) || empty($binary)) {
                throw new InvalidArgumentException('All binary names must be non-empty strings');
            }
        }
    }
}
