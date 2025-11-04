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

namespace ValksorDev\Build\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use ValksorDev\Build\Binary\BinaryAssetManager;

/**
 * Tests for BinaryAssetManager class.
 *
 * Tests binary downloading, version management, and asset extraction functionality.
 */
final class BinaryAssetManagerTest extends TestCase
{
    private string $targetDir;
    private string $tempDir;

    /**
     * @throws ReflectionException
     */
    public function testAssetsPresent(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [
                ['pattern' => 'test-binary', 'target' => 'test-binary', 'executable' => true],
                ['pattern' => 'config.json', 'target' => 'config.json', 'executable' => false],
            ],
            'target_dir' => $this->targetDir,
        ];

        $manager = new BinaryAssetManager($config);

        $method = new ReflectionClass($manager)->getMethod('assetsPresent');

        // Test with no assets
        mkdir($this->targetDir, 0o755, true);
        self::assertFalse($method->invoke($manager, $this->targetDir));

        // Test with some assets present
        touch($this->targetDir . '/test-binary');
        self::assertFalse($method->invoke($manager, $this->targetDir));

        // Test with all assets present
        touch($this->targetDir . '/config.json');
        self::assertTrue($method->invoke($manager, $this->targetDir));
    }

    public function testConstructorWithValidConfig(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [
                ['pattern' => 'linux-x64', 'target' => 'test-binary', 'executable' => true],
            ],
            'target_dir' => $this->targetDir,
        ];

        new BinaryAssetManager($config);
        $this->expectNotToPerformAssertions();
    }

    /**
     * @throws ReflectionException
     */
    public function testEnsureDirectory(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [],
            'target_dir' => $this->targetDir . '/nested/dir',
        ];

        $manager = new BinaryAssetManager($config);
        $method = new ReflectionClass($manager)->getMethod('ensureDirectory');

        // Directory doesn't exist yet
        self::assertDirectoryDoesNotExist($this->targetDir . '/nested/dir');

        // Should create nested directories
        $method->invoke($manager, $this->targetDir . '/nested/dir');
        self::assertDirectoryExists($this->targetDir . '/nested/dir');

        // Should not fail if directory already exists
        $method->invoke($manager, $this->targetDir . '/nested/dir');
        self::assertDirectoryExists($this->targetDir . '/nested/dir');
    }

    /**
     * @throws JsonException
     */
    public function testEnsureLatestWithExistingCurrentVersion(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [
                ['pattern' => 'linux-x64', 'target' => 'test-binary', 'executable' => true],
            ],
            'target_dir' => $this->targetDir,
        ];

        // Create version file
        mkdir($this->targetDir, 0o755, true);
        $versionFile = $this->targetDir . '/version.json';
        file_put_contents($versionFile, json_encode(['tag' => 'v1.0.0', 'updated_at' => date('c')], JSON_THROW_ON_ERROR));

        // Create dummy binary file
        touch($this->targetDir . '/test-binary');

        $manager = new BinaryAssetManager($config);

        // This should try to check latest version and potentially update
        // We expect it to fail during GitHub API call
        $this->expectException(RuntimeException::class);
        $manager->ensureLatest();
    }

    /**
     * @throws JsonException
     */
    public function testEnsureLatestWithGithubZipSource(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github-zip',
            'repo' => 'test/repo',
            'assets' => [],
            'target_dir' => $this->targetDir,
        ];

        $manager = new BinaryAssetManager($config);

        // This will try to download ZIP from GitHub, which will fail in test environment
        $this->expectException(RuntimeException::class);
        $manager->ensureLatest();
    }

    /**
     * @throws JsonException
     */
    public function testEnsureLatestWithNoExistingAssets(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [
                ['pattern' => 'linux-x64', 'target' => 'test-binary', 'executable' => true],
            ],
            'target_dir' => $this->targetDir,
        ];

        $manager = new BinaryAssetManager($config);

        // This will try to fetch from GitHub, which will fail in test environment
        // We expect a RuntimeException due to network/API issues
        $this->expectException(RuntimeException::class);
        $manager->ensureLatest();
    }

    /**
     * @throws JsonException
     */
    public function testEnsureLatestWithNpmSource(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'npm',
            'npm_package' => 'test-package',
            'assets' => [],
            'target_dir' => $this->targetDir,
        ];

        $manager = new BinaryAssetManager($config);

        // This will try to fetch from npm registry, which will fail in test environment
        $this->expectException(RuntimeException::class);
        $manager->ensureLatest();
    }

    /**
     * @throws ReflectionException
     */
    public function testGetLatestReleaseWithInvalidGithubResponse(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [],
            'target_dir' => $this->targetDir,
        ];

        $manager = new BinaryAssetManager($config);

        $method = new ReflectionClass($manager)->getMethod('fetchLatestRelease');

        // This will try to fetch from GitHub API and fail
        $this->expectException(RuntimeException::class);
        $method->invoke($manager);
    }

    /**
     * @throws ReflectionException
     */
    public function testLogFunctionality(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [],
            'target_dir' => $this->targetDir,
        ];

        $manager = new BinaryAssetManager($config);
        $loggedMessages = [];

        $logger = function (string $message) use (&$loggedMessages): void {
            $loggedMessages[] = $message;
        };

        $method = new ReflectionClass($manager)->getMethod('log');

        $method->invoke($manager, $logger, 'Test message');
        self::assertSame(['Test message'], $loggedMessages);

        // Test with null logger
        $method->invoke($manager, null, 'Should not be logged');
        self::assertSame(['Test message'], $loggedMessages);
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    public function testVersionFileOperations(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [
                ['pattern' => 'linux-x64', 'target' => 'test-binary', 'executable' => true],
            ],
            'target_dir' => $this->targetDir,
        ];

        $manager = new BinaryAssetManager($config);

        // Test reading non-existent version file
        $method = new ReflectionClass($manager)->getMethod('readCurrentTag');

        $currentTag = $method->invoke($manager, $this->targetDir);
        self::assertNull($currentTag);

        // Test reading valid version file
        mkdir($this->targetDir, 0o755, true);
        $versionFile = $this->targetDir . '/version.json';
        file_put_contents($versionFile, json_encode(['tag' => 'v1.0.0'], JSON_THROW_ON_ERROR));

        $currentTag = $method->invoke($manager, $this->targetDir);
        self::assertSame('v1.0.0', $currentTag);

        // Test reading invalid JSON
        file_put_contents($versionFile, 'invalid json');
        $currentTag = $method->invoke($manager, $this->targetDir);
        self::assertNull($currentTag);

        // Test reading JSON without tag
        file_put_contents($versionFile, json_encode(['version' => '1.0.0'], JSON_THROW_ON_ERROR));
        $currentTag = $method->invoke($manager, $this->targetDir);
        self::assertNull($currentTag);
    }

    /**
     * @throws ReflectionException
     * @throws JsonException
     */
    public function testWriteVersionFile(): void
    {
        $config = [
            'name' => 'test-tool',
            'source' => 'github',
            'repo' => 'test/repo',
            'assets' => [],
            'target_dir' => $this->targetDir,
        ];

        $manager = new BinaryAssetManager($config);
        $method = new ReflectionClass($manager)->getMethod('writeVersionFile');

        mkdir($this->targetDir, 0o755, true);

        $method->invoke($manager, $this->targetDir, 'v1.0.0', '1.0.0');

        $versionFile = $this->targetDir . '/version.json';
        self::assertFileExists($versionFile);

        $content = json_decode(file_get_contents($versionFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('v1.0.0', $content['tag']);
        self::assertSame('1.0.0', $content['version']);
        self::assertArrayHasKey('downloaded_at', $content);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/binary_asset_manager_test_' . uniqid('', true);
        $this->targetDir = $this->tempDir . '/binaries';

        if (!mkdir($this->tempDir, 0o755, true)) {
            throw new RuntimeException('Failed to create temp directory');
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(
        string $dir,
    ): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
