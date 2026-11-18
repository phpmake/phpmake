<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

    /** Tests execution results for built-in task types. */
final class TaskExecutionTest extends TestCase
{
    private Logger $logger;

    /**
     * This method is called before the first test method in the test class is executed.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {}

    /**
     * This method is called after the last test method in the test class has been executed.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        gc_collect_cycles();
    }

    /**
     * This method is called BEFORE each test method.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger(false, true, true);
    }

    /**
     * This method is called AFTER each test method.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Methods finalization codes.
        parent::tearDown();
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testCreateDirectoryTask(): void
    {
        // Arrange — path must stay inside the project directory.
        $tempDir = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-test-dir-' . uniqid('', true);
        $params = ['name' => $tempDir];
        // Action
        $task = new CreateDirectoryTask($params, $this->logger);
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
        $this->assertTrue(is_dir($tempDir));
        // Cleanup
        rmdir($tempDir);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testExecTask(): void
    {
        // Arrange
        $params = ['command' => PHP_BINARY, 'args' => ['-r', 'echo "Test";']];
        $task = new ExecTask($params, $this->logger);
        // Action
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testEchoTask(): void
    {
        // Arrange
        $params = ['message' => 'Test message'];
        $task = new EchoTask($params, $this->logger);
        // Action
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
    }

    /** Returns failure when a regular file blocks directory creation. */
    public function testCreateDirectoryTaskReportsFilesystemFailure(): void
    {
        // Arrange
        $parentFile = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-parent-' . bin2hex(random_bytes(6));
        file_put_contents($parentFile, 'not a directory');
        $task = new CreateDirectoryTask(['name' => $parentFile . DIRECTORY_SEPARATOR . 'child'], $this->logger);
        try {
        // Assert
        // Act
            self::assertFalse($task->execute(false, true));
        } finally {
            unlink($parentFile);
        }
    }

    /** Rejects an exec timeout above the supported maximum. */
    public function testExecTaskRejectsTimeoutAboveMaximum(): void
    {
        // Arrange
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 86400');
        // Act
        new ExecTask(['command' => PHP_BINARY, 'timeout' => 86401], $this->logger);
    }



    /** Successful task execution produces no console output in silent mode. */
    public function testTaskExecutorSuppressesAllSuccessfulTaskOutputInSilentMode(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'SilentTest',
            'Silent output contract',
            '1.0.0',
            ['build' => ['tasks' => [[
                'type' => 'echo',
                'params' => ['message' => 'This must remain hidden'],
            ]]]],
            'build'
        );
        $taskFactory = new \PhpMake\Task\TaskFactory($this->logger);
        $executor = new \PhpMake\Task\TaskExecutor();

        ob_start();
        // Act
        $exitCode = $executor->executeTasks($config, ['build'], $taskFactory, false, true);
        $output = ob_get_clean();

        // Assert
        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
    }



    /** Invalid execution-order targets stop execution with a failure code. */
    public function testTaskExecutorRejectsMissingExecutionTarget(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'InvalidOrder',
            'Missing target',
            '1.0.0',
            ['build' => ['tasks' => []]],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['missing'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }

    /** Targets without a task list fail instead of being silently skipped. */
    public function testTaskExecutorRejectsMalformedTaskList(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'MalformedTasks',
            'Malformed task list',
            '1.0.0',
            ['build' => ['tasks' => 'not-an-array']],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['build'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }

    /** Invalid task configuration is caught and reported as a failed build. */
    public function testTaskExecutorHandlesTaskFactoryFailure(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'InvalidTask',
            'Invalid task configuration',
            '1.0.0',
            ['build' => ['tasks' => [['type' => '', 'params' => []]]]],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['build'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }

    /** A task returning false stops the target and returns a failure code. */
    public function testTaskExecutorStopsAfterTaskFailure(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'FailingTask',
            'Non-zero process exit',
            '1.0.0',
            ['build' => ['tasks' => [[
                'type' => 'exec',
                'params' => ['command' => PHP_BINARY, 'args' => ['-r', 'exit(7);']],
            ]]]],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['build'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }


    /** Non-array task entries fail safely without invoking the task factory. */
    public function testTaskExecutorRejectsNonArrayTaskEntry(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'MalformedTaskEntry',
            'Task entry is not an object',
            '1.0.0',
            ['build' => ['tasks' => ['invalid-task-entry']]],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['build'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }


    /** Rejects an echo task when its required message parameter is absent. */
    public function testEchoTaskRequiresMessageParameter(): void
    {
        // Arrange
        $params = [];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing 'message' parameter for echo task.");

        // Act
        new EchoTask($params, $this->logger);
    }

    /** Allows an explicitly empty echo message. */
    public function testEchoTaskAllowsEmptyMessage(): void
    {
        // Arrange
        $task = new EchoTask(['message' => ''], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertTrue($result);
    }


    /** Terminates commands that exceed the bounded output capture limit. */
    public function testExecTaskRejectsExcessiveOutput(): void
    {
        // Arrange
        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', 'echo str_repeat("x", 1100000);'],
        ], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
    }

    /** Rejects unbalanced quotes before attempting to launch a process. */
    public function testExecTaskRejectsUnbalancedCommandQuotes(): void
    {
        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('unbalanced quote');

        // Act
        new ExecTask(['command' => PHP_BINARY . ' "unterminated'], $this->logger);
    }

}
