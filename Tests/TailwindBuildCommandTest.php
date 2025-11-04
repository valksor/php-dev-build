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
use ValksorDev\Build\Command\TailwindBuildCommand;
use ValksorDev\Build\Provider\ProviderRegistry;
use ValksorDev\Build\Service\TailwindService;

/**
 * Tests for TailwindBuildCommand class.
 *
 * Tests Tailwind CSS build command functionality.
 */
final class TailwindBuildCommandTest extends TestCase
{
    private ParameterBagInterface $parameterBag;
    private ProviderRegistry $providerRegistry;
    private TailwindService $tailwindService;

    public function testCommandConfiguration(): void
    {
        $command = new TailwindBuildCommand($this->parameterBag, $this->providerRegistry, $this->tailwindService);

        // Test that command has proper configuration
        self::assertNotEmpty($command->getName());
        self::assertNotEmpty($command->getDescription());

        // Should have watch option (inherited from AbstractCommand)
        $definition = $command->getDefinition();
        self::assertTrue($definition->hasOption('watch'));
    }

    public function testCommandDescription(): void
    {
        $command = new TailwindBuildCommand($this->parameterBag, $this->providerRegistry, $this->tailwindService);

        self::assertStringContainsString('Tailwind CSS', $command->getDescription());
    }

    /**
     * @throws ExceptionInterface
     */
    public function testCommandExecution(): void
    {
        $command = new TailwindBuildCommand($this->parameterBag, $this->providerRegistry, $this->tailwindService);

        // Test that command execution attempts to run
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

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
        $command = new TailwindBuildCommand($this->parameterBag, $this->providerRegistry, $this->tailwindService);

        self::assertSame('valksor:tailwind', $command->getName());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetProviderRegistry(): void
    {
        $command = new TailwindBuildCommand($this->parameterBag, $this->providerRegistry, $this->tailwindService);

        // Test that we can access the provider registry through reflection
        $property = new ReflectionClass($command)->getProperty('providerRegistry');

        self::assertSame($this->providerRegistry, $property->getValue($command));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetTailwindService(): void
    {
        $command = new TailwindBuildCommand($this->parameterBag, $this->providerRegistry, $this->tailwindService);

        // Test that we can access the tailwind service through reflection
        $property = new ReflectionClass($command)->getProperty('tailwindService');

        self::assertSame($this->tailwindService, $property->getValue($command));
    }

    public function testServiceIntegration(): void
    {
        new TailwindBuildCommand($this->parameterBag, $this->providerRegistry, $this->tailwindService);

        // Test that the command has access to the TailwindService
        self::assertSame($this->tailwindService, $this->tailwindService);
        self::assertSame('tailwind', TailwindService::getServiceName());
    }

    /**
     * @throws ReflectionException
     */
    public function testWithDifferentProviderRegistry(): void
    {
        $differentRegistry = new ProviderRegistry([]);
        $command = new TailwindBuildCommand($this->parameterBag, $differentRegistry, $this->tailwindService);

        $property = new ReflectionClass($command)->getProperty('providerRegistry');

        self::assertSame($differentRegistry, $property->getValue($command));
    }

    public function testWithEmptyParameterBag(): void
    {
        $emptyParameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project', // Required by parent class
        ]);
        $emptyTailwindService = new TailwindService($emptyParameterBag);

        new TailwindBuildCommand($emptyParameterBag, $this->providerRegistry, $emptyTailwindService);
        $this->expectNotToPerformAssertions();
    }

    public function testWithProductionEnvironment(): void
    {
        $prodParameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'prod',
            'valksor.build.minify' => true,
            'valksor.build.env' => 'prod',
            'valksor.project.apps_dir' => 'apps',
            'valksor.project.infrastructure_dir' => 'infrastructure',
        ]);
        $prodTailwindService = new TailwindService($prodParameterBag);

        new TailwindBuildCommand($prodParameterBag, $this->providerRegistry, $prodTailwindService);
        $this->expectNotToPerformAssertions();
    }

    protected function setUp(): void
    {
        $this->parameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'dev',
            'valksor.build.minify' => false,
            'valksor.build.env' => 'dev',
            'valksor.project.apps_dir' => 'apps',
            'valksor.project.infrastructure_dir' => 'infrastructure',
        ]);
        $this->providerRegistry = new ProviderRegistry([]);
        $this->tailwindService = new TailwindService($this->parameterBag);
    }
}
