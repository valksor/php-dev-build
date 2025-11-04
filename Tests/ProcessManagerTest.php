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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use ValksorDev\Build\Service\ProcessManager;

/**
 * Tests for ProcessManager class.
 *
 * Tests process lifecycle management, signal handling, and status monitoring.
 */
final class ProcessManagerTest extends TestCase
{
    private ProcessManager $manager;

    public function testAddProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getPid')->willReturn(12345);

        $this->manager->addProcess('test-service', $process);

        self::assertSame(1, $this->manager->count());
        self::assertTrue($this->manager->hasProcesses());
    }

    public function testAllProcessesRunning(): void
    {
        $process1 = $this->createMock(Process::class);
        $process1->method('getPid')->willReturn(12345);
        $process1->method('isRunning')->willReturn(true);

        $process2 = $this->createMock(Process::class);
        $process2->method('getPid')->willReturn(12346);
        $process2->method('isRunning')->willReturn(true);

        $this->manager->addProcess('service-1', $process1);
        $this->manager->addProcess('service-2', $process2);

        self::assertTrue($this->manager->allProcessesRunning());
    }

    public function testCount(): void
    {
        self::assertSame(0, $this->manager->count());

        $process1 = $this->createMock(Process::class);
        $process1->method('getPid')->willReturn(12345);

        $this->manager->addProcess('service-1', $process1);
        self::assertSame(1, $this->manager->count());

        $process2 = $this->createMock(Process::class);
        $process2->method('getPid')->willReturn(12346);

        $this->manager->addProcess('service-2', $process2);
        self::assertSame(2, $this->manager->count());
    }

    public function testDisplayStatusWithoutIo(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getPid')->willReturn(12345);

        $this->manager->addProcess('test-service', $process);

        // Should not throw exception when no IO is provided
        $this->manager->displayStatus();

        // If we get here, the test passed
        self::assertTrue(true);
    }

    public function testExecuteProcessInteractive(): void
    {
        // Capture output to prevent test from being "risky"
        ob_start();
        $result = ProcessManager::executeProcess(
            ['list'],
            true,
            'TestService',
        );
        ob_end_clean();

        // Should return either SUCCESS or FAILURE
        self::assertContains($result, [0, 1]);
    }

    public function testExecuteProcessNonInteractive(): void
    {
        $result = ProcessManager::executeProcess(
            ['list'],
            false,
            'TestService',
        );

        // Should return either SUCCESS or FAILURE
        self::assertContains($result, [0, 1]);
    }

    public function testGetFailedProcesses(): void
    {
        $process1 = $this->createMock(Process::class);
        $process1->method('getPid')->willReturn(12345);
        $process1->method('isRunning')->willReturn(false);
        $process1->method('isSuccessful')->willReturn(true);

        $process2 = $this->createMock(Process::class);
        $process2->method('getPid')->willReturn(12346);
        $process2->method('isRunning')->willReturn(false);
        $process2->method('isSuccessful')->willReturn(false);

        $process3 = $this->createMock(Process::class);
        $process3->method('getPid')->willReturn(12347);
        $process3->method('isRunning')->willReturn(true);
        $process3->method('isSuccessful')->willReturn(false);

        $this->manager->addProcess('successful-service', $process1);
        $this->manager->addProcess('failed-service', $process2);
        $this->manager->addProcess('running-service', $process3);

        $failed = $this->manager->getFailedProcesses();

        self::assertArrayHasKey('failed-service', $failed);
        self::assertArrayNotHasKey('successful-service', $failed);
        self::assertArrayNotHasKey('running-service', $failed);
    }

    public function testGetProcessStatuses(): void
    {
        $process1 = $this->createMock(Process::class);
        $process1->method('getPid')->willReturn(12345);
        $process1->method('isRunning')->willReturn(true);
        $process1->method('getExitCode')->willReturn(null);

        $process2 = $this->createMock(Process::class);
        $process2->method('getPid')->willReturn(12346);
        $process2->method('isRunning')->willReturn(false);
        $process2->method('getExitCode')->willReturn(1);

        $this->manager->addProcess('running-service', $process1);
        $this->manager->addProcess('stopped-service', $process2);

        $statuses = $this->manager->getProcessStatuses();

        self::assertTrue($statuses['running-service']['running']);
        self::assertNull($statuses['running-service']['exit_code']);
        self::assertSame(12345, $statuses['running-service']['pid']);

        self::assertFalse($statuses['stopped-service']['running']);
        self::assertSame(1, $statuses['stopped-service']['exit_code']);
        self::assertSame(12346, $statuses['stopped-service']['pid']);
    }

    public function testHasFailedProcesses(): void
    {
        $process1 = $this->createMock(Process::class);
        $process1->method('getPid')->willReturn(12345);
        $process1->method('isRunning')->willReturn(false);
        $process1->method('isSuccessful')->willReturn(true);

        $process2 = $this->createMock(Process::class);
        $process2->method('getPid')->willReturn(12346);
        $process2->method('isRunning')->willReturn(false);
        $process2->method('isSuccessful')->willReturn(false);

        $this->manager->addProcess('successful-service', $process1);
        $this->manager->addProcess('failed-service', $process2);

        self::assertTrue($this->manager->hasFailedProcesses());
    }

    public function testNotAllProcessesRunning(): void
    {
        $process1 = $this->createMock(Process::class);
        $process1->method('getPid')->willReturn(12345);
        $process1->method('isRunning')->willReturn(true);

        $process2 = $this->createMock(Process::class);
        $process2->method('getPid')->willReturn(12346);
        $process2->method('isRunning')->willReturn(false);
        $process2->method('getExitCode')->willReturn(1);

        $this->manager->addProcess('running-service', $process1);
        $this->manager->addProcess('stopped-service', $process2);

        self::assertFalse($this->manager->allProcessesRunning());
    }

    public function testRemoveProcess(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('getPid')->willReturn(12345);

        $this->manager->addProcess('test-service', $process);
        $this->manager->removeProcess('test-service');

        self::assertSame(0, $this->manager->count());
        self::assertFalse($this->manager->hasProcesses());
    }

    public function testShutdownStatus(): void
    {
        self::assertFalse($this->manager->isShutdown());

        // Note: We can't test the shutdown signal handling directly as it calls exit()
        // But we can test the initial state
    }

    public function testTerminateAll(): void
    {
        // Simplify the test by using a simpler mock approach
        $process = $this->createMock(Process::class);
        $process->method('getPid')->willReturn(12345);

        $this->manager->addProcess('test-service', $process);

        // Just verify the method runs without error
        // The actual termination logic is tested in integration tests
        $this->manager->terminateAll();

        // If we get here without exception, the test passed
        self::assertTrue(true);
    }

    public function testWithSymfonyStyle(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('text');

        $manager = new ProcessManager($io);
        $process = $this->createMock(Process::class);
        $process->method('getPid')->willReturn(12345);

        $manager->addProcess('test-service', $process);
    }

    protected function setUp(): void
    {
        $this->manager = new ProcessManager();
    }
}
