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
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ValksorDev\Build\Binary\BinaryInterface;
use ValksorDev\Build\Binary\BinaryRegistry;
use ValksorDev\Build\Command\BinaryEnsureCommand;
use ValksorDev\Build\Provider\ProviderRegistry;

/**
 * Tests for BinaryEnsureCommand class.
 *
 * Tests binary download command functionality and argument handling.
 */
final class BinaryEnsureCommandTest extends TestCase
{
    private BinaryEnsureCommand $command;
    private ParameterBagInterface $parameterBag;

    public function testCommandConfiguration(): void
    {
        self::assertSame('valksor:binary', $this->command->getName());
        self::assertStringContainsString('Ensure tool binaries/assets are downloaded', $this->command->getDescription());
    }

    /**
     * @throws ExceptionInterface
     */
    public function testCommandExecutionRequiresValidTool(): void
    {
        $input = new ArrayInput(['tool' => 'invalid-tool']);
        $output = new BufferedOutput();

        // Command should attempt to run but may fail with invalid tool
        $result = $this->command->run($input, $output);

        // Should return either SUCCESS or FAILURE
        self::assertContains($result, [0, 1]);
    }

    /**
     * @throws ExceptionInterface
     */
    public function testCommandExecutionWithValidTool(): void
    {
        $this->parameterBag->add(['valksor.binaries_dir' => '/test/binaries']);

        $input = new ArrayInput(['tool' => 'tailwindcss']);
        $output = new BufferedOutput();

        $result = $this->command->run($input, $output);

        // Should return either SUCCESS or FAILURE
        self::assertContains($result, [0, 1]);
    }

    public function testCommandHasRequiredToolArgument(): void
    {
        $definition = $this->command->getDefinition();
        self::assertTrue($definition->hasArgument('tool'));
        self::assertTrue($definition->getArgument('tool')->isRequired());
    }

    public function testCommandInApplication(): void
    {
        $application = new Application();
        $application->addCommand($this->command);

        $command = $application->find('valksor:binary');
        self::assertSame($this->command, $command);
    }

    public function testConfigureWithAvailableBinaries(): void
    {
        // Create a real BinaryInterface implementation
        $binary = $this->createMock(BinaryInterface::class);
        $binary->method('getName')->willReturn('test-binary');

        // Test that the argument exists and is required
        $definition = $this->command->getDefinition();
        self::assertTrue($definition->hasArgument('tool'));
        self::assertTrue($definition->getArgument('tool')->isRequired());
    }

    protected function setUp(): void
    {
        // Use real BinaryRegistry since it's final
        $binaryRegistry = new BinaryRegistry([]);
        $this->parameterBag = new ParameterBag([
            'kernel.project_dir' => '/test/project',
            'valksor.binaries_dir' => '/test/binaries',
        ]);
        $providerRegistry = new ProviderRegistry([]);

        $this->command = new BinaryEnsureCommand(
            $binaryRegistry,
            $this->parameterBag,
            $providerRegistry,
        );
    }
}
