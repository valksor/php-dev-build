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
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Command\AbstractCommand;
use ValksorDev\Build\Provider\ProviderRegistry;

/**
 * Tests for AbstractCommand class.
 *
 * Tests abstract base functionality for build commands.
 */
final class AbstractCommandTest extends TestCase
{
    private ParameterBagInterface $parameterBag;
    private ProviderRegistry $providerRegistry;

    public function testCommandInheritance(): void
    {
        $command = new class($this->parameterBag, $this->providerRegistry) extends AbstractCommand {
            public function getCommandName(): string
            {
                return $this->getName() ?? 'test:command';
            }
        };

        // Test that it's properly instantiated and has access to parent functionality
        self::assertInstanceOf(AbstractCommand::class, $command);
        self::assertIsString($command->getCommandName());
    }

    public function testGetProviderRegistry(): void
    {
        $command = new class($this->parameterBag, $this->providerRegistry) extends AbstractCommand {
            public function getProviderRegistryForTest(): ProviderRegistry
            {
                return $this->providerRegistry;
            }
        };

        self::assertSame($this->providerRegistry, $command->getProviderRegistryForTest());
    }

    public function testWithDifferentProviderRegistry(): void
    {
        $differentRegistry = new ProviderRegistry([]);

        $command = new class($this->parameterBag, $differentRegistry) extends AbstractCommand {
            public function getProviderRegistryForTest(): ProviderRegistry
            {
                return $this->providerRegistry;
            }
        };

        self::assertSame($differentRegistry, $command->getProviderRegistryForTest());
    }

    public function testWithEmptyParameterBag(): void
    {
        $emptyParameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project', // Required by parent class
        ]);

        $command = new class($emptyParameterBag, $this->providerRegistry) extends AbstractCommand {
        };

        self::assertInstanceOf(AbstractCommand::class, $command);
    }

    public function testWithProductionEnvironment(): void
    {
        $prodParameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'prod',
        ]);

        $command = new class($prodParameterBag, $this->providerRegistry) extends AbstractCommand {
        };

        self::assertInstanceOf(AbstractCommand::class, $command);
    }

    protected function setUp(): void
    {
        $this->parameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'kernel.environment' => 'dev',
        ]);
        $this->providerRegistry = new ProviderRegistry([]);
    }
}
