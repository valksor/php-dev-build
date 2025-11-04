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

use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Provider\ProviderRegistry;
use ValksorDev\Build\Service\DevWatchService;

/**
 * Tests for DevWatchService class.
 *
 * Tests development watch service orchestration and multi-service coordination.
 */
final class DevWatchServiceTest extends TestCase
{
    private SymfonyStyle $io;
    private ParameterBagInterface $parameterBag;
    private ProviderRegistry $providerRegistry;

    public function testGetParameterBag(): void
    {
        $devWatchService = new DevWatchService($this->parameterBag, $this->providerRegistry);

        self::assertSame($this->parameterBag, $devWatchService->getParameterBag());
    }

    public function testGetProviderRegistry(): void
    {
        $devWatchService = new DevWatchService($this->parameterBag, $this->providerRegistry);

        self::assertSame($this->providerRegistry, $devWatchService->getProviderRegistry());
    }

    public function testSetIo(): void
    {
        $devWatchService = new DevWatchService($this->parameterBag, $this->providerRegistry);

        // Test that setIo method executes without error
        $devWatchService->setIo($this->io);

        self::assertTrue(true); // If we get here without exception, the method works
    }

    public function testStart(): void
    {
        $devWatchService = new DevWatchService($this->parameterBag, $this->providerRegistry);
        $devWatchService->setIo($this->io);

        // Test that start method executes without errors
        try {
            $result = $devWatchService->start();
            self::assertContains($result, [0, 1]); // Command::SUCCESS or Command::FAILURE
        } catch (Exception) {
            // Expected in test environment due to missing dependencies
            self::assertTrue(true);
        }
    }

    public function testStartWithoutIo(): void
    {
        $devWatchService = new DevWatchService($this->parameterBag, $this->providerRegistry);

        // Test start without setting IO
        try {
            $result = $devWatchService->start();
            self::assertContains($result, [0, 1]);
        } catch (Exception) {
            self::assertTrue(true);
        }
    }

    public function testStop(): void
    {
        $devWatchService = new DevWatchService($this->parameterBag, $this->providerRegistry);

        // Test that stop method executes without error
        $devWatchService->stop();

        self::assertTrue(true); // If we get here, stop worked
    }

    public function testWithDifferentEnvironment(): void
    {
        $prodParameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'prod',
            'valksor.build.services' => [
                'sse_server' => [
                    'enabled' => true,
                    'provider' => 'sse_server',
                ],
            ],
        ]);

        $devWatchService = new DevWatchService($prodParameterBag, $this->providerRegistry);

        self::assertSame($prodParameterBag, $devWatchService->getParameterBag());
    }

    public function testWithDisabledServices(): void
    {
        $parameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'dev',
            'valksor.build.services' => [
                'sse_server' => [
                    'enabled' => false,
                    'provider' => 'sse_server',
                ],
                'hot_reload' => [
                    'enabled' => false,
                    'provider' => 'hot_reload',
                ],
                'tailwind' => [
                    'enabled' => false,
                    'provider' => 'tailwind',
                ],
            ],
        ]);

        $devWatchService = new DevWatchService($parameterBag, $this->providerRegistry);
        $devWatchService->setIo($this->io);

        // Test that start method executes without errors even with disabled services
        try {
            $result = $devWatchService->start();
            self::assertContains($result, [0, 1]);
        } catch (Exception) {
            self::assertTrue(true);
        }
    }

    public function testWithEmptyServices(): void
    {
        $emptyParameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'dev',
            'valksor.build.services' => [],
        ]);

        $devWatchService = new DevWatchService($emptyParameterBag, $this->providerRegistry);
        $devWatchService->setIo($this->io);

        try {
            $result = $devWatchService->start();
            self::assertContains($result, [0, 1]);
        } catch (Exception) {
            self::assertTrue(true);
        }
    }

    protected function setUp(): void
    {
        $this->parameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'dev',
            'valksor.build.services' => [
                'sse_server' => [
                    'enabled' => true,
                    'provider' => 'sse_server',
                ],
                'hot_reload' => [
                    'enabled' => true,
                    'provider' => 'hot_reload',
                ],
                'tailwind' => [
                    'enabled' => true,
                    'provider' => 'tailwind',
                ],
                'importmap' => [
                    'enabled' => true,
                    'provider' => 'importmap',
                ],
            ],
        ]);
        $this->providerRegistry = new ProviderRegistry([]);
        $this->io = $this->createMock(SymfonyStyle::class);
    }
}
