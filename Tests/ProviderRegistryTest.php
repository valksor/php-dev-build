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
use ValksorDev\Build\Provider\ProviderInterface;
use ValksorDev\Build\Provider\ProviderRegistry;

/**
 * Tests for ProviderRegistry class.
 *
 * Tests service provider registration, retrieval, filtering, and dependency resolution.
 */
final class ProviderRegistryTest extends TestCase
{
    private ProviderInterface $mockProvider1;
    private ProviderRegistry $registry;

    public function testGetAvailableNames(): void
    {
        $names = $this->registry->getAvailableNames();
        self::assertContains('service-1', $names);
        self::assertContains('service-2', $names);
        self::assertIsArray($names);
    }

    public function testGetExistingProvider(): void
    {
        $provider = $this->registry->get('service-1');
        self::assertSame($this->mockProvider1, $provider);
    }

    public function testGetNonExistentProviderThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No service provider registered for: non-existent');

        $this->registry->get('non-existent');
    }

    public function testGetProvidersByFlag(): void
    {
        $servicesConfig = [
            'service-1' => [
                'enabled' => true,
                'flags' => ['dev' => true, 'prod' => false],
            ],
            'service-2' => [
                'enabled' => true,
                'flags' => ['dev' => false, 'prod' => true],
            ],
            'disabled-service' => [
                'enabled' => false,
                'flags' => ['dev' => true, 'prod' => true],
            ],
        ];

        $devProviders = $this->registry->getProvidersByFlag($servicesConfig, 'dev');
        self::assertArrayHasKey('service-1', $devProviders);
        self::assertArrayNotHasKey('service-2', $devProviders);
        self::assertArrayNotHasKey('disabled-service', $devProviders);

        $prodProviders = $this->registry->getProvidersByFlag($servicesConfig, 'prod');
        self::assertArrayNotHasKey('service-1', $prodProviders);
        self::assertArrayHasKey('service-2', $prodProviders);
        self::assertArrayNotHasKey('disabled-service', $prodProviders);
    }

    public function testGetProvidersByFlagSkipsMissingProviders(): void
    {
        $servicesConfig = [
            'service-with-missing-provider' => [
                'enabled' => true,
                'flags' => ['dev' => true],
                'provider' => 'missing-provider',
            ],
        ];

        $providers = $this->registry->getProvidersByFlag($servicesConfig, 'dev');
        self::assertEmpty($providers);
    }

    public function testGetProvidersByFlagWithCustomProvider(): void
    {
        $servicesConfig = [
            'custom-service' => [
                'enabled' => true,
                'flags' => ['dev' => true],
                'provider' => 'service-1', // Use existing provider
            ],
        ];

        $providers = $this->registry->getProvidersByFlag($servicesConfig, 'dev');
        self::assertArrayHasKey('custom-service', $providers);
        self::assertSame($this->mockProvider1, $providers['custom-service']);
    }

    public function testHasProvider(): void
    {
        self::assertTrue($this->registry->has('service-1'));
        self::assertTrue($this->registry->has('service-2'));
        self::assertFalse($this->registry->has('non-existent'));
    }

    public function testMultipleProvidersRegistration(): void
    {
        $provider1 = $this->createMock(ProviderInterface::class);
        $provider1->method('getName')->willReturn('provider-1');
        $provider1->method('getServiceOrder')->willReturn(1);
        $provider1->method('getDependencies')->willReturn([]);

        $provider2 = $this->createMock(ProviderInterface::class);
        $provider2->method('getName')->willReturn('provider-2');
        $provider2->method('getServiceOrder')->willReturn(2);
        $provider2->method('getDependencies')->willReturn([]);

        $registry = new ProviderRegistry([$provider1, $provider2]);

        self::assertTrue($registry->has('provider-1'));
        self::assertTrue($registry->has('provider-2'));
        self::assertCount(2, $registry->getAvailableNames());
    }

    public function testProviderDependencyResolution(): void
    {
        $dependencyProvider = $this->createMock(ProviderInterface::class);
        $dependencyProvider->method('getName')->willReturn('dependency');
        $dependencyProvider->method('getServiceOrder')->willReturn(1);
        $dependencyProvider->method('getDependencies')->willReturn([]);

        $dependentProvider = $this->createMock(ProviderInterface::class);
        $dependentProvider->method('getName')->willReturn('dependent');
        $dependentProvider->method('getServiceOrder')->willReturn(2);
        $dependentProvider->method('getDependencies')->willReturn(['dependency']);

        $servicesConfig = [
            'dependent' => [
                'enabled' => true,
                'flags' => ['dev' => true],
            ],
            'dependency' => [
                'enabled' => true,
                'flags' => ['dev' => true],
            ],
        ];

        $providers = new ProviderRegistry([$dependencyProvider, $dependentProvider])->getProvidersByFlag($servicesConfig, 'dev');

        // Dependency should come before dependent service
        $providerNames = array_keys($providers);
        $dependencyIndex = array_search('dependency', $providerNames, true);
        $dependentIndex = array_search('dependent', $providerNames, true);

        self::assertLessThan($dependentIndex, $dependencyIndex);
    }

    public function testProviderOverride(): void
    {
        $newProvider = $this->createMock(ProviderInterface::class);
        $newProvider->method('getName')->willReturn('service-1');
        $newProvider->method('getServiceOrder')->willReturn(3);
        $newProvider->method('getDependencies')->willReturn([]);

        $this->registry->register($newProvider);

        self::assertTrue($this->registry->has('service-1'));
        self::assertSame($newProvider, $this->registry->get('service-1'));
        self::assertNotSame($this->mockProvider1, $this->registry->get('service-1'));
    }

    public function testRegisterProvider(): void
    {
        $newProvider = $this->createMock(ProviderInterface::class);
        $newProvider->method('getName')->willReturn('new-service');
        $newProvider->method('getServiceOrder')->willReturn(3);
        $newProvider->method('getDependencies')->willReturn([]);

        $this->registry->register($newProvider);

        self::assertTrue($this->registry->has('new-service'));
        self::assertSame($newProvider, $this->registry->get('new-service'));
    }

    public function testValidateProviders(): void
    {
        $servicesConfig = [
            'service-1' => [
                'enabled' => true,
                'flags' => ['dev' => true],
            ],
            'missing-provider' => [
                'enabled' => true,
                'flags' => ['dev' => true],
            ],
            'disabled-service' => [
                'enabled' => false,
                'flags' => ['dev' => true],
            ],
        ];

        $missing = $this->registry->validateProviders($servicesConfig);
        self::assertContains('missing-provider', $missing);
        self::assertNotContains('service-1', $missing);
        self::assertCount(1, $missing);
    }

    protected function setUp(): void
    {
        // Use real ProviderRegistry since it's final
        $this->registry = new ProviderRegistry([]);

        $this->mockProvider1 = $this->createMock(ProviderInterface::class);
        $this->mockProvider1->method('getName')->willReturn('service-1');
        $this->mockProvider1->method('getServiceOrder')->willReturn(1);
        $this->mockProvider1->method('getDependencies')->willReturn([]);

        $mockProvider2 = $this->createMock(ProviderInterface::class);
        $mockProvider2->method('getName')->willReturn('service-2');
        $mockProvider2->method('getServiceOrder')->willReturn(2);
        $mockProvider2->method('getDependencies')->willReturn([]);

        $this->registry->register($this->mockProvider1);
        $this->registry->register($mockProvider2);
    }
}
