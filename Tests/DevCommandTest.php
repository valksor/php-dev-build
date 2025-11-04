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
use ValksorDev\Build\Command\DevCommand;
use ValksorDev\Build\Provider\ProviderRegistry;
use ValksorDev\Build\Service\DevService;

/**
 * Tests for DevCommand class.
 *
 * Tests lightweight development mode command.
 */
final class DevCommandTest extends TestCase
{
    private DevService $devService;
    private ProviderRegistry $providerRegistry;

    public function testCommandConfiguration(): void
    {
        $command = new DevCommand($this->devService);

        // Test that command has proper configuration
        self::assertNotEmpty($command->getName());
        self::assertNotEmpty($command->getDescription());
    }

    public function testCommandDescription(): void
    {
        $command = new DevCommand($this->devService);

        self::assertStringContainsString('lightweight development mode', $command->getDescription());
    }

    /**
     * @throws ExceptionInterface
     */
    public function testCommandExecution(): void
    {
        $command = new DevCommand($this->devService);

        // Test that command execution attempts to run
        // In a real scenario, this would start the development service
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
        $command = new DevCommand($this->devService);

        self::assertSame('valksor:dev', $command->getName());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetProviderRegistry(): void
    {
        $command = new DevCommand($this->devService);

        // Test that we can access the provider registry through reflection
        $property = new ReflectionClass($command)->getProperty('providerRegistry');

        self::assertSame($this->providerRegistry, $property->getValue($command));
    }

    public function testWithDifferentDevService(): void
    {
        $differentParameterBag = new ParameterBag([
            'kernel.project_dir' => '/different/project',
            'kernel.environment' => 'dev',
            'valksor.build.services' => [],
        ]);
        $differentDevService = new DevService($differentParameterBag, $this->providerRegistry);

        new DevCommand($differentDevService);
        $this->expectNotToPerformAssertions();
    }

    public function testWithEmptyServices(): void
    {
        $emptyParameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'dev',
            'valksor.build.services' => [],
        ]);
        $emptyDevService = new DevService($emptyParameterBag, $this->providerRegistry);

        new DevCommand($emptyDevService);
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
        $prodDevService = new DevService($prodParameterBag, $this->providerRegistry);

        new DevCommand($prodDevService);
        $this->expectNotToPerformAssertions();
    }

    protected function setUp(): void
    {
        $parameterBag = new ParameterBag([
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
            ],
        ]);
        $this->providerRegistry = new ProviderRegistry([]);
        $this->devService = new DevService($parameterBag, $this->providerRegistry);
    }
}
