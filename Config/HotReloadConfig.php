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

use InvalidArgumentException;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_replace;

/**
 * Hot reload configuration value object.
 *
 * Typed configuration for hot reload settings including file transformations.
 */
readonly class HotReloadConfig
{
    /**
     * @param array<string,FileTransformationRule> $fileTransformations
     * @param array<string>                        $watchDirs
     * @param array<string,float>                  $extendedSuffixes
     */
    public function __construct(
        public bool $enableFileWatching,
        public array $fileTransformations,
        public array $watchDirs,
        public float $debounceDelay,
        public array $extendedExtensions,
        public array $extendedSuffixes,
    ) {
        $this->validate();
    }

    /**
     * Get debounce delay for a specific file extension.
     */
    public function getDebounceDelayForFile(
        string $filename,
    ): float {
        foreach ($this->extendedSuffixes as $suffix => $delay) {
            if (str_ends_with($filename, $suffix)) {
                return $delay;
            }
        }

        return $this->debounceDelay;
    }

    /**
     * Get transformation rule for a file pattern.
     */
    public function getTransformationRule(
        string $filename,
    ): ?FileTransformationRule {
        foreach ($this->fileTransformations as $pattern => $rule) {
            if ($this->matchesPattern($filename, $pattern)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Create from raw configuration array.
     *
     * @param array<string,mixed> $config
     */
    public static function fromArray(
        array $config,
    ): self {
        $fileTransformations = [];
        $transformationsConfig = $config['file_transformations'] ?? [];

        foreach ($transformationsConfig as $pattern => $ruleConfig) {
            if (!is_array($ruleConfig)) {
                throw new InvalidArgumentException("File transformation rule for '{$pattern}' must be an array");
            }

            $fileTransformations[$pattern] = new FileTransformationRule(
                outputPattern: $ruleConfig['output_pattern'] ?? throw new InvalidArgumentException("output_pattern is required for transformation '{$pattern}'"),
                debounceDelay: (float) ($ruleConfig['debounce_delay'] ?? 0.5),
                trackOutput: (bool) ($ruleConfig['track_output'] ?? true),
                watchDirs: $ruleConfig['watch_dirs'] ?? [],
            );
        }

        return new self(
            enableFileWatching: (bool) ($config['enable_file_watching'] ?? true),
            fileTransformations: $fileTransformations,
            watchDirs: $config['watch_dirs'] ?? ['/apps', '/shared', '/src'],
            debounceDelay: (float) ($config['debounce_delay'] ?? 0.3),
            extendedExtensions: $config['extended_extensions'] ?? [],
            extendedSuffixes: $config['extended_suffixes'] ?? ['.tailwind.css' => 0.5],
        );
    }

    /**
     * Check if filename matches a pattern.
     */
    private function matchesPattern(
        string $filename,
        string $pattern,
    ): bool {
        // Simple glob pattern matching
        $pattern = '/^' . str_replace(['*', '.'], ['.*', '\.'], $pattern) . '$/';

        return 1 === preg_match($pattern, $filename);
    }

    private function validate(): void
    {
        if ($this->debounceDelay < 0) {
            throw new InvalidArgumentException('Debounce delay cannot be negative');
        }

        foreach ($this->extendedSuffixes as $suffix => $delay) {
            if (!is_string($suffix)) {
                throw new InvalidArgumentException('Extended suffix keys must be strings');
            }

            if (!is_float($delay) && !is_int($delay)) {
                throw new InvalidArgumentException("Extended suffix delay for '{$suffix}' must be a number");
            }

            if ($delay < 0) {
                throw new InvalidArgumentException("Extended suffix delay for '{$suffix}' cannot be negative");
            }
        }
    }
}

/**
 * File transformation rule configuration.
 */
readonly class FileTransformationRule
{
    /**
     * @param array<string> $watchDirs
     */
    public function __construct(
        public string $outputPattern,
        public float $debounceDelay,
        public bool $trackOutput,
        public array $watchDirs,
    ) {
        $this->validate();
    }

    /**
     * Transform input filename to output filename.
     */
    public function transformFilename(
        string $inputFilename,
    ): string {
        $pathInfo = pathinfo($inputFilename);
        $path = $pathInfo['dirname'] ?? '.';
        $name = $pathInfo['filename'] ?? $inputFilename;

        $output = $this->outputPattern;
        $output = str_replace('{path}', $path, $output);

        return str_replace('{name}', $name, $output);
    }

    private function validate(): void
    {
        if (empty($this->outputPattern)) {
            throw new InvalidArgumentException('Output pattern cannot be empty');
        }

        if ($this->debounceDelay < 0) {
            throw new InvalidArgumentException('Debounce delay cannot be negative');
        }

        if (!str_contains($this->outputPattern, '{name}') && !str_contains($this->outputPattern, '{path}')) {
            throw new InvalidArgumentException('Output pattern must contain {name} or {path} placeholders');
        }
    }
}
