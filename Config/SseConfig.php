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

use function ltrim;
use function sprintf;

/**
 * SSE (Server-Sent Events) configuration value object.
 *
 * Typed configuration for SSE server settings.
 */
readonly class SseConfig
{
    public function __construct(
        public string $bind,
        public int $port,
        public string $path,
        public string $domain,
        public bool $enableFileWatching = false,
    ) {
        $this->validate();
    }

    /**
     * Get the base path with leading slash.
     */
    public function getBasePath(): string
    {
        return '/' . ltrim($this->path, '/');
    }

    /**
     * Get the health check endpoint path.
     */
    public function getHealthPath(): string
    {
        return rtrim($this->getBasePath(), '/') . '/healthz';
    }

    /**
     * Get the full URL for the health check endpoint.
     */
    public function getHealthUrl(
        ?bool $useTls = null,
    ): string {
        $protocol = ($useTls ?? $this->hasTlsSupport()) ? 'https' : 'http';

        return sprintf(
            '%s://%s:%d%s',
            $protocol,
            $this->bind,
            $this->port,
            $this->getHealthPath(),
        );
    }

    /**
     * Get the full URL for the SSE endpoint.
     */
    public function getUrl(
        ?bool $useTls = null,
    ): string {
        $protocol = ($useTls ?? $this->hasTlsSupport()) ? 'https' : 'http';

        return sprintf(
            '%s://%s:%d%s',
            $protocol,
            $this->bind,
            $this->port,
            $this->getBasePath(),
        );
    }

    /**
     * Check if TLS is likely available for this domain.
     */
    public function hasTlsSupport(): bool
    {
        $certPath = '/etc/ssl/private/' . $this->domain . '.crt';
        $keyPath = '/etc/ssl/private/' . $this->domain . '.key';

        return is_file($certPath) && is_file($keyPath);
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
            bind: $config['bind'] ?? '0.0.0.0',
            port: (int) ($config['port'] ?? 3000),
            path: $config['path'] ?? '/sse',
            domain: $config['domain'] ?? 'localhost',
            enableFileWatching: (bool) ($config['enable_file_watching'] ?? false),
        );
    }

    private function validate(): void
    {
        if (empty($this->bind)) {
            throw new InvalidArgumentException('SSE bind address cannot be empty');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('SSE port must be between 1 and 65535');
        }

        if (empty($this->path)) {
            throw new InvalidArgumentException('SSE path cannot be empty');
        }

        if (empty($this->domain)) {
            throw new InvalidArgumentException('SSE domain cannot be empty');
        }
    }
}
