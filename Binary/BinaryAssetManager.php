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

namespace ValksorDev\Build\Binary;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use Valksor\Functions\Local\Traits\_MkDir;
use ZipArchive;

use function array_key_exists;
use function chmod;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_file;
use function json_decode;
use function json_encode;
use function ltrim;
use function php_uname;
use function rename;
use function sprintf;
use function str_contains;
use function stream_context_create;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DATE_ATOM;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_OS_FAMILY;

/**
 * Generic binary/asset manager for downloading and managing tool binaries and assets from GitHub releases.
 * Supports tailwindcss, esbuild, daisyui, and other tools.
 */
final class BinaryAssetManager
{
    private const string VERSION_FILE = 'version.json';

    /**
     * @param array{
     *     name: string,
     *     source: 'github'|'npm'|'github-zip',
     *     repo?: string,
     *     npm_package?: string,
     *     assets: array<int,array{pattern:string,target:string,executable:bool,extract_path?:string}>,
     *     target_dir: string,
     *     version_in_path?: bool
     * } $toolConfig
     */
    public function __construct(
        private readonly array $toolConfig,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function ensureLatest(
        ?callable $logger = null,
    ): string {
        $targetDir = $this->toolConfig['target_dir'];
        $this->ensureDirectory($targetDir);

        $latest = $this->fetchLatestRelease();
        $currentTag = $this->readCurrentTag($targetDir);
        $assetsPresent = $this->assetsPresent($targetDir);

        if (null !== $currentTag && $assetsPresent && $currentTag === $latest['tag']) {
            $this->log($logger, sprintf('%s assets already current (%s).', $this->toolConfig['name'], $currentTag));

            return $currentTag;
        }

        $this->log($logger, sprintf('Downloading %s assets (%s)…', $this->toolConfig['name'], $latest['tag']));

        if ('npm' === $this->toolConfig['source']) {
            $this->downloadNpmAsset($latest['version'], $targetDir);
        } elseif ('github-zip' === $this->toolConfig['source']) {
            $this->downloadGithubZipAsset($latest['tag'], $latest['version'], $targetDir);
        } else {
            foreach ($this->toolConfig['assets'] as $assetConfig) {
                $this->downloadAsset($latest['tag'], $assetConfig, $targetDir);
            }
        }

        $this->writeVersionFile($targetDir, $latest['tag'], $latest['version']);
        $this->log($logger, sprintf('%s assets updated.', $this->toolConfig['name']));

        return $latest['tag'];
    }

    /**
     * Factory method from custom tool definition array.
     */
    public static function createFromDefinition(
        array $definition,
    ): self {
        if (!isset($definition['name'], $definition['source'], $definition['assets'], $definition['target_dir'])) {
            throw new RuntimeException('Tool definition must include name, source, assets, and target_dir.');
        }

        if (isset($definition['repo'])) {
            throw new RuntimeException('GitHub source requires repo parameter.');
        }

        if (isset($definition['npm_package'])) {
            throw new RuntimeException('npm source requires npm_package parameter.');
        }

        return new self($definition);
    }

    /**
     * Detect current platform for binary downloads.
     */
    public static function detectPlatform(): string
    {
        $os = PHP_OS_FAMILY;
        $arch = php_uname('m');

        if ('Darwin' === $os) {
            return str_contains($arch, 'arm') || str_contains($arch, 'aarch64') ? 'darwin-arm64' : 'darwin-x64';
        }

        if ('Linux' === $os) {
            return str_contains($arch, 'arm') || str_contains($arch, 'aarch64') ? 'linux-arm64' : 'linux-x64';
        }

        if ('Windows' === $os) {
            return 'windows-x64';
        }

        return 'linux-x64'; // Default fallback
    }

    private function assetsPresent(
        string $targetDir,
    ): bool {
        foreach ($this->toolConfig['assets'] as $assetConfig) {
            if (!is_file($targetDir . '/' . $assetConfig['target'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{pattern:string,target:string,executable:bool} $assetConfig
     */
    private function downloadAsset(
        string $tag,
        array $assetConfig,
        string $targetDir,
    ): void {
        $url = sprintf(
            'https://github.com/%s/releases/download/%s/%s',
            $this->toolConfig['repo'],
            $tag,
            $assetConfig['pattern'],
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: valksor-binary-manager',
                ],
                'follow_location' => 1,
                'timeout' => 30,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if (false === $content) {
            throw new RuntimeException(sprintf('Failed to download %s asset: %s', $this->toolConfig['name'], $assetConfig['pattern']));
        }

        $targetPath = $targetDir . '/' . $assetConfig['target'];

        if (false === file_put_contents($targetPath, $content)) {
            throw new RuntimeException(sprintf('Failed to write %s asset to %s', $this->toolConfig['name'], $targetPath));
        }

        if ($assetConfig['executable']) {
            @chmod($targetPath, 0o755);
        }
    }

    private function downloadGithubZipAsset(
        string $tag,
        string $version,
        string $targetDir,
    ): void {
        $assetConfig = $this->toolConfig['assets'][0];

        // Use tag (stripped of 'v' prefix) for filename pattern, not the version name
        $cleanVersion = ltrim($tag, 'v');
        $zipFilename = sprintf($assetConfig['pattern'], $cleanVersion);
        $url = sprintf(
            'https://github.com/%s/releases/download/%s/%s',
            $this->toolConfig['repo'],
            $tag,
            $zipFilename,
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: valksor-binary-manager',
                ],
                'follow_location' => 1,
                'timeout' => 30,
            ],
        ]);

        $zipContent = @file_get_contents($url, false, $context);

        if (false === $zipContent) {
            throw new RuntimeException(sprintf('Failed to download %s zip: %s', $this->toolConfig['name'], $url));
        }

        $tmpZip = sys_get_temp_dir() . '/valksor-' . uniqid(more_entropy: true) . '.zip';

        if (false === file_put_contents($tmpZip, $zipContent)) {
            throw new RuntimeException(sprintf('Failed to write temporary zip for %s.', $this->toolConfig['name']));
        }

        try {
            $zip = new ZipArchive();

            if (true !== $zip->open($tmpZip)) {
                throw new RuntimeException(sprintf('Unable to open %s zip archive.', $this->toolConfig['name']));
            }

            $zip->extractTo($targetDir);
            $zip->close();
        } finally {
            @unlink($tmpZip);
        }
    }

    private function downloadNpmAsset(
        string $version,
        string $targetDir,
    ): void {
        $assetConfig = $this->toolConfig['assets'][0];
        $platform = self::detectPlatform();
        $npmPackage = sprintf('@esbuild/%s', $platform);
        $url = sprintf('https://registry.npmjs.org/%s/-/%s-%s.tgz', $npmPackage, $platform, $version);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: valksor-binary-manager',
                'follow_location' => 1,
                'timeout' => 30,
            ],
        ]);

        $tgzContent = @file_get_contents($url, false, $context);

        if (false === $tgzContent) {
            throw new RuntimeException(sprintf('Failed to download %s from npm registry: %s', $this->toolConfig['name'], $url));
        }

        $tmpDir = sys_get_temp_dir() . '/valksor-binary-' . uniqid(more_entropy: true);
        $this->ensureDirectory($tmpDir);

        try {
            $tgzPath = $tmpDir . '/package.tgz';

            if (false === file_put_contents($tgzPath, $tgzContent)) {
                throw new RuntimeException(sprintf('Failed to write temporary tarball for %s.', $this->toolConfig['name']));
            }

            $extractPath = $assetConfig['extract_path'] ?? 'package/bin/esbuild';
            $extractDir = $tmpDir . '/extracted';
            $this->ensureDirectory($extractDir);

            exec(sprintf('tar -xzf %s -C %s %s 2>&1', escapeshellarg($tgzPath), escapeshellarg($extractDir), escapeshellarg($extractPath)), $output, $returnCode);

            if (0 !== $returnCode) {
                throw new RuntimeException(sprintf('Failed to extract %s tarball: %s', $this->toolConfig['name'], implode("\n", $output)));
            }

            $extractedBinary = $extractDir . '/' . $extractPath;

            if (!is_file($extractedBinary)) {
                throw new RuntimeException(sprintf('Extracted binary not found at %s', $extractedBinary));
            }

            $targetPath = $targetDir . '/' . $assetConfig['target'];

            if (!rename($extractedBinary, $targetPath)) {
                throw new RuntimeException(sprintf('Failed to move %s binary to %s', $this->toolConfig['name'], $targetPath));
            }

            if ($assetConfig['executable']) {
                @chmod($targetPath, 0o755);
            }
        } finally {
            exec(sprintf('rm -rf %s 2>&1', escapeshellarg($tmpDir)));
        }
    }

    private function ensureDirectory(
        string $directory,
    ): void {
        static $_helper = null;

        if (null === $_helper) {
            $_helper = new class {
                use _MkDir;
            };
        }

        $_helper->mkdir($directory);
    }

    /**
     * @return array{tag: string, version: string}
     */
    private function fetchLatestNpmVersion(): array
    {
        $packageUrl = sprintf('https://registry.npmjs.org/%s/latest', $this->toolConfig['npm_package']);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: valksor-binary-manager',
                'timeout' => 15,
            ],
        ]);

        $response = @file_get_contents($packageUrl, false, $context);

        if (false === $response) {
            throw new RuntimeException(sprintf('Failed to fetch latest version for %s from npm registry.', $this->toolConfig['name']));
        }

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid JSON response from npm registry for %s: %s', $this->toolConfig['name'], $exception->getMessage()));
        }

        if (!array_key_exists('version', $data)) {
            throw new RuntimeException(sprintf('Unexpected npm registry response structure for %s.', $this->toolConfig['name']));
        }

        return [
            'tag' => $data['version'],
            'version' => $data['version'],
        ];
    }

    /**
     * @return array{tag: string, version: string}
     */
    private function fetchLatestRelease(): array
    {
        if ('npm' === $this->toolConfig['source']) {
            return $this->fetchLatestNpmVersion();
        }

        $apiUrl = sprintf('https://api.github.com/repos/%s/releases/latest', $this->toolConfig['repo']);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: valksor-binary-manager',
                    'Accept: application/vnd.github+json',
                ],
                'timeout' => 15,
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $context);

        if (false === $response) {
            throw new RuntimeException(sprintf('Failed to fetch latest release for %s from GitHub API.', $this->toolConfig['name']));
        }

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid JSON response from GitHub API for %s: %s', $this->toolConfig['name'], $exception->getMessage()));
        }

        if (!array_key_exists('tag_name', $data)) {
            throw new RuntimeException(sprintf('Unexpected GitHub API response structure for %s.', $this->toolConfig['name']));
        }

        return [
            'tag' => $data['tag_name'],
            'version' => $data['name'] ?? $data['tag_name'],
        ];
    }

    private function log(
        ?callable $logger,
        string $message,
    ): void {
        if (null !== $logger) {
            $logger($message);
        }
    }

    private function readCurrentTag(
        string $targetDir,
    ): ?string {
        $versionFile = $targetDir . '/' . self::VERSION_FILE;

        if (!is_file($versionFile)) {
            return null;
        }

        $raw = @file_get_contents($versionFile);

        if (false === $raw || '' === $raw) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return array_key_exists('tag', $data) ? (string) $data['tag'] : null;
    }

    /**
     * @throws JsonException
     */
    private function writeVersionFile(
        string $targetDir,
        string $tag,
        string $version,
    ): void {
        $data = [
            'tag' => $tag,
            'version' => $version,
            'downloaded_at' => new DateTimeImmutable()->format(DATE_ATOM),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $versionFile = $targetDir . '/' . self::VERSION_FILE;

        if (false === file_put_contents($versionFile, $json)) {
            throw new RuntimeException(sprintf('Failed to write version file for %s.', $this->toolConfig['name']));
        }
    }
}
