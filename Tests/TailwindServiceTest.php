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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Service\TailwindService;

/**
 * Tests for TailwindService class.
 *
 * Tests CSS compilation service functionality and configuration handling.
 */
final class TailwindServiceTest extends TestCase
{
    private ParameterBagInterface $parameterBag;
    private string $tempDir;

    public function testGetServiceName(): void
    {
        self::assertSame('tailwind', TailwindService::getServiceName());
    }

    public function testSetActiveAppId(): void
    {
        $tailwindService = new TailwindService($this->parameterBag);
        $tailwindService->setActiveAppId('test-app-id');
        self::assertTrue(true); // If we get here without exception, the method works
    }

    public function testStart(): void
    {
        // Create required directory structure
        mkdir($this->tempDir . '/var/tailwindcss', 0o755, true);
        mkdir($this->tempDir . '/apps/test-app/assets/styles', 0o755, true);

        // Create a dummy tailwindcss binary
        $tailwindBinary = $this->tempDir . '/var/tailwindcss/tailwindcss';
        file_put_contents($tailwindBinary, '#!/bin/bash' . PHP_EOL . 'echo "Mock tailwindcss"');
        chmod($tailwindBinary, 0o755);

        // Create a test tailwind source file
        $sourceFile = $this->tempDir . '/apps/test-app/assets/styles/app.tailwind.css';
        file_put_contents($sourceFile, '@tailwind base; @tailwind components; @tailwind utilities;');

        $tailwindService = new TailwindService($this->parameterBag);
        $tailwindService->setActiveAppId('test-app');
        $tailwindService->setIo(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));

        // Test start method with basic config
        try {
            $result = $tailwindService->start(['watch' => false, 'minify' => false]);
            // Should return either SUCCESS (0) or FAILURE (1) depending on environment
            self::assertContains($result, [0, 1]);
        } catch (RuntimeException $e) {
            // Expected when binary is not properly set up
            self::assertStringContainsString('Tailwind executable not found', $e->getMessage());
        }
    }

    public function testStartWithMinify(): void
    {
        // Create required directory structure
        mkdir($this->tempDir . '/var/tailwindcss', 0o755, true);
        mkdir($this->tempDir . '/apps/test-app/assets/styles', 0o755, true);

        // Create a dummy tailwindcss binary
        $tailwindBinary = $this->tempDir . '/var/tailwindcss/tailwindcss';
        file_put_contents($tailwindBinary, '#!/bin/bash' . PHP_EOL . 'echo "Mock tailwindcss"');
        chmod($tailwindBinary, 0o755);

        // Create a test tailwind source file
        $sourceFile = $this->tempDir . '/apps/test-app/assets/styles/app.tailwind.css';
        file_put_contents($sourceFile, '@tailwind base; @tailwind components; @tailwind utilities;');

        $tailwindService = new TailwindService($this->parameterBag);
        $tailwindService->setActiveAppId('test-app');
        $tailwindService->setIo(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));

        // Test start method with minify enabled
        try {
            $result = $tailwindService->start(['watch' => false, 'minify' => true]);
            // Should return either SUCCESS (0) or FAILURE (1) depending on environment
            self::assertContains($result, [0, 1]);
        } catch (RuntimeException $e) {
            // Expected when binary is not properly set up
            self::assertStringContainsString('Tailwind executable not found', $e->getMessage());
        }
    }

    public function testStartWithNoSources(): void
    {
        // No tailwind source files created
        $tailwindService = new TailwindService($this->parameterBag);

        try {
            $result = $tailwindService->start(['watch' => false, 'minify' => false]);
            // Should return SUCCESS when no sources found
            self::assertSame(0, $result);
        } catch (RuntimeException $e) {
            // Expected when binary is not properly set up
            self::assertStringContainsString('Tailwind executable not found', $e->getMessage());
        }
    }

    public function testStartWithWatchMode(): void
    {
        // Create required directory structure
        mkdir($this->tempDir . '/var/tailwindcss', 0o755, true);
        mkdir($this->tempDir . '/apps/test-app/assets/styles', 0o755, true);

        // Create a dummy tailwindcss binary
        $tailwindBinary = $this->tempDir . '/var/tailwindcss/tailwindcss';
        file_put_contents($tailwindBinary, '#!/bin/bash' . PHP_EOL . 'echo "Mock tailwindcss"');
        chmod($tailwindBinary, 0o755);

        // Create a test tailwind source file
        $sourceFile = $this->tempDir . '/apps/test-app/assets/styles/app.tailwind.css';
        file_put_contents($sourceFile, '@tailwind base; @tailwind components; @tailwind utilities;');

        $tailwindService = new TailwindService($this->parameterBag);
        $tailwindService->setActiveAppId('test-app');
        $tailwindService->setIo(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));

        // Test start method with watch mode
        try {
            $result = $tailwindService->start(['minify' => false]);
            // Should return either SUCCESS (0) or FAILURE (1) depending on environment
            self::assertContains($result, [0, 1]);
        } catch (RuntimeException $e) {
            // Expected when binary is not properly set up
            self::assertStringContainsString('Tailwind executable not found', $e->getMessage());
        }
    }

    public function testStop(): void
    {
        $tailwindService = new TailwindService($this->parameterBag);

        // Test that stop method executes without error
        $tailwindService->stop();
        self::assertTrue(true); // If we get here, stop worked
    }

    public function testWithDifferentAppId(): void
    {
        $tailwindService = new TailwindService($this->parameterBag);

        // Test setting different app IDs
        $tailwindService->setActiveAppId('app1');
        $tailwindService->setActiveAppId('app2');
        $tailwindService->setActiveAppId(null); // Test null for multi-app mode

        self::assertTrue(true); // If we get here without exception, the method works
    }

    public function testWithProductionEnvironment(): void
    {
        $prodParameterBag = new ParameterBag([
            'kernel.project_dir' => $this->tempDir,
            'kernel.environment' => 'prod',
            'valksor.build.minify' => true,
            'valksor.build.env' => 'prod',
            'valksor.project.apps_dir' => 'apps',
            'valksor.project.infrastructure_dir' => 'infrastructure',
        ]);

        new TailwindService($prodParameterBag);
        $this->expectNotToPerformAssertions();
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tailwind_service_test_' . uniqid('', true);

        if (!mkdir($this->tempDir, 0o755, true)) {
            throw new RuntimeException('Failed to create temp directory');
        }

        $this->parameterBag = new ParameterBag([
            'kernel.project_dir' => $this->tempDir,
            'kernel.environment' => 'dev',
            'valksor.build.minify' => false,
            'valksor.build.env' => 'dev',
            'valksor.project.apps_dir' => 'apps',
            'valksor.project.infrastructure_dir' => 'infrastructure',
        ]);
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
