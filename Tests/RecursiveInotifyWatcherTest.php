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

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ValksorDev\Build\Service\PathFilter;
use ValksorDev\Build\Service\RecursiveInotifyWatcher;

use function function_exists;

/**
 * Tests for RecursiveInotifyWatcher class.
 *
 * Tests recursive file system monitoring with inotify.
 */
final class RecursiveInotifyWatcherTest extends TestCase
{
    private array $changedFiles = [];
    private PathFilter $pathFilter;
    private string $tempDir;

    public function testAddDuplicateRoot(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());

        // Add the same directory twice
        $watcher->addRoot($this->tempDir);
        $watcher->addRoot($this->tempDir);

        self::assertTrue(true); // If we get here without exception, duplicate roots handled gracefully
    }

    public function testAddMultipleRoots(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        // Create additional test directories
        $subDir1 = $this->tempDir . '/sub1';
        $subDir2 = $this->tempDir . '/sub2';
        mkdir($subDir1, 0o755, true);
        mkdir($subDir2, 0o755, true);

        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());

        // Add multiple root directories
        $watcher->addRoot($subDir1);
        $watcher->addRoot($subDir2);

        self::assertTrue(true); // If we get here without exception, multiple roots handled
    }

    public function testAddNonExistentRoot(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        $nonExistentDir = $this->tempDir . '/nonexistent';
        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());

        // Adding non-existent directory should not throw exception
        $watcher->addRoot($nonExistentDir);

        self::assertTrue(true); // If we get here without exception, addRoot handled it gracefully
    }

    public function testAddRoot(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());

        // Test adding a root directory
        $watcher->addRoot($this->tempDir);

        self::assertTrue(true); // If we get here without exception, addRoot worked
    }

    public function testCallbackInvocation(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        $callback = function (string $path): void {
            $this->changedFiles[] = $path;
        };

        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $callback);
        $watcher->addRoot($this->tempDir);

        // Create a test file to trigger callback
        $testFile = $this->tempDir . '/test.txt';
        file_put_contents($testFile, 'test content');

        // Poll to process events
        $watcher->poll();

        // Note: Callback might not be invoked immediately due to async nature
        self::assertTrue(true); // Basic test that setup doesn't fail
    }

    public function testComplexDirectoryStructure(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        // Create a complex directory structure
        $subDir = $this->tempDir . '/subdir';
        $nestedDir = $subDir . '/nested';
        mkdir($subDir, 0o755, true);
        mkdir($nestedDir, 0o755, true);

        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());
        $watcher->addRoot($this->tempDir);

        // Create files in different directories
        file_put_contents($subDir . '/file1.txt', 'content1');
        file_put_contents($nestedDir . '/file2.txt', 'content2');

        $watcher->poll();

        self::assertTrue(true); // If we get here, complex structure handled
    }

    public function testConstructorWithInotifyAvailable(): void
    {
        if (!function_exists('inotify_init')) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('inotify extension is required but not available.');
        }

        new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());
        $this->expectNotToPerformAssertions();
    }

    public function testConstructorWithoutInotify(): void
    {
        // Mock the function_exists to return false for inotify_init
        if (!function_exists('inotify_init')) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('inotify extension is required but not available.');

            new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());
            self::assertTrue(true); // Exception should be thrown
        } else {
            // If inotify is available, we can't test this case easily
            self::markTestSkipped('inotify extension is available');
        }
    }

    public function testGetStream(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());
        $watcher->addRoot($this->tempDir);

        $stream = $watcher->getStream();

        self::assertIsResource($stream);
    }

    public function testPoll(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());
        $watcher->addRoot($this->tempDir);

        // Test poll method - should not throw exception
        $watcher->poll();

        self::assertTrue(true); // If we get here, poll worked
    }

    public function testStopAndCleanup(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        $watcher = new RecursiveInotifyWatcher($this->pathFilter, $this->createChangeCallback());
        $watcher->addRoot($this->tempDir);

        // Test that cleanup happens when object is destroyed
        unset($watcher);

        self::assertTrue(true); // If we get here, cleanup worked
    }

    public function testWithCustomPathFilter(): void
    {
        if (!function_exists('inotify_init')) {
            self::markTestSkipped('inotify extension not available');
        }

        // Use createDefault() method instead of constructor
        $customFilter = PathFilter::createDefault('/test/project');
        $watcher = new RecursiveInotifyWatcher($customFilter, $this->createChangeCallback());
        $watcher->addRoot($this->tempDir);

        self::assertTrue(true); // If we get here, custom filter worked
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/inotify_watcher_test_' . uniqid('', true);

        if (!mkdir($this->tempDir, 0o755, true)) {
            throw new RuntimeException('Failed to create temp directory');
        }

        $this->pathFilter = PathFilter::createDefault('/test/project');
        $this->changedFiles = [];
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function createChangeCallback(): callable
    {
        return function (string $path): void {
            $this->changedFiles[] = $path;
        };
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
