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
use ValksorDev\Build\Binary\BinaryInterface;
use ValksorDev\Build\Binary\BinaryRegistry;

/**
 * Tests for BinaryRegistry class.
 *
 * Tests binary provider registration, retrieval, and management.
 */
final class BinaryRegistryTest extends TestCase
{
    private BinaryInterface $mockProvider;
    private BinaryRegistry $registry;

    public function testGetAvailableNames(): void
    {
        $names = $this->registry->getAvailableNames();
        self::assertContains('test-binary', $names);
        self::assertIsArray($names);
    }

    public function testGetExistingProvider(): void
    {
        $provider = $this->registry->get('test-binary');
        self::assertSame($this->mockProvider, $provider);
    }

    public function testGetNonExistentProviderThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No binary provider registered for: non-existent');

        $this->registry->get('non-existent');
    }

    public function testHasProvider(): void
    {
        self::assertTrue($this->registry->has('test-binary'));
        self::assertFalse($this->registry->has('non-existent'));
    }

    public function testMultipleProvidersRegistration(): void
    {
        $provider1 = $this->createStub(BinaryInterface::class);
        $provider1->method('getName')->willReturn('binary-1');

        $provider2 = $this->createStub(BinaryInterface::class);
        $provider2->method('getName')->willReturn('binary-2');

        $registry = new BinaryRegistry([$provider1, $provider2]);

        self::assertTrue($registry->has('binary-1'));
        self::assertTrue($registry->has('binary-2'));
        self::assertCount(2, $registry->getAvailableNames());
    }

    public function testProviderOverride(): void
    {
        $newProvider = $this->createStub(BinaryInterface::class);
        $newProvider->method('getName')->willReturn('test-binary');

        $this->registry->register($newProvider);

        self::assertTrue($this->registry->has('test-binary'));
        self::assertSame($newProvider, $this->registry->get('test-binary'));
        self::assertNotSame($this->mockProvider, $this->registry->get('test-binary'));
    }

    public function testRegisterProvider(): void
    {
        $newProvider = $this->createStub(BinaryInterface::class);
        $newProvider->method('getName')->willReturn('new-binary');

        $this->registry->register($newProvider);

        self::assertTrue($this->registry->has('new-binary'));
        self::assertSame($newProvider, $this->registry->get('new-binary'));
    }

    protected function setUp(): void
    {
        $this->mockProvider = $this->createStub(BinaryInterface::class);
        $this->mockProvider->method('getName')->willReturn('test-binary');

        $this->registry = new BinaryRegistry([]);
        $this->registry->register($this->mockProvider);
    }
}
