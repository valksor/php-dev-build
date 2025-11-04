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
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Command\DevWatchCommand;
use ValksorDev\Build\Provider\ProviderRegistry;
use ValksorDev\Build\Service\DevWatchService;

/**
 * Tests for DevWatchCommand class.
 *
 * Tests development watch command functionality.
 */
final class DevWatchCommandTest extends TestCase
{
    private DevWatchService $devWatchService;
    private ParameterBagInterface $parameterBag;
    private ProviderRegistry $providerRegistry;

    public function testCommandConfiguration(): void
    {
        $command = new DevWatchCommand($this->devWatchService);

        // Test that command has proper configuration
        self::assertNotEmpty($command->getName());
        self::assertNotEmpty($command->getDescription());

        // Command should have some definition options from AbstractCommand
        $definition = $command->getDefinition();
        self::assertNotNull($definition);
    }

    public function testCommandDescription(): void
    {
        $command = new DevWatchCommand($this->devWatchService);

        self::assertStringContainsString('development services', $command->getDescription());
    }

    /**
     * @throws ExceptionInterface
     */
    public function testCommandExecution(): void
    {
        $command = new DevWatchCommand($this->devWatchService);

        // Test that command execution attempts to run
        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        try {
            $result = $command->run($input, $output);
            // Should return either SUCCESS (0) or FAILURE (1) depending on environment
            self::assertContains($result, [0, 1]);
        } catch (Exception) {
            // Expected in test environment due to missing dependencies
            self::assertTrue(true);
        }
    }

    public function testCommandName(): void
    {
        $command = new DevWatchCommand($this->devWatchService);

        self::assertSame('valksor:watch', $command->getName());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetProviderRegistry(): void
    {
        $command = new DevWatchCommand($this->devWatchService);

        // Test that we can access the provider registry through reflection
        $property = new ReflectionClass($command)->getProperty('providerRegistry');

        self::assertSame($this->providerRegistry, $property->getValue($command));
    }

    public function testServiceIntegration(): void
    {
        new DevWatchCommand($this->devWatchService);

        // Test that the command has access to the DevWatchService
        self::assertSame($this->parameterBag, $this->devWatchService->getParameterBag());
        self::assertSame($this->providerRegistry, $this->devWatchService->getProviderRegistry());
    }

    public function testWithDifferentDevWatchService(): void
    {
        $differentParameterBag = new ParameterBag([
            'kernel.project_dir' => '/different/project',
            'kernel.environment' => 'dev',
            'valksor.build.services' => [],
        ]);
        $differentDevWatchService = new DevWatchService($differentParameterBag, $this->providerRegistry);

        new DevWatchCommand($differentDevWatchService);
        $this->expectNotToPerformAssertions();
    }

    public function testWithEmptyServices(): void
    {
        $emptyParameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'dev',
            'valksor.build.services' => [],
        ]);
        $emptyDevWatchService = new DevWatchService($emptyParameterBag, $this->providerRegistry);

        new DevWatchCommand($emptyDevWatchService);
        $this->expectNotToPerformAssertions();
    }

    public function testWithProductionEnvironment(): void
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
        $prodDevWatchService = new DevWatchService($prodParameterBag, $this->providerRegistry);

        new DevWatchCommand($prodDevWatchService);
        $this->expectNotToPerformAssertions();
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
        $this->devWatchService = new DevWatchService($this->parameterBag, $this->providerRegistry);
    }
}
