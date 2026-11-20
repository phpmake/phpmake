<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpMake\Task\TaskFactory;
use PhpMake\Utilities\Logger;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ZipTask;

/** Tests creation of supported task types and rejection of unknown types. */
final class TaskFactoryTest extends TestCase
{
    private TaskFactory $factory;

    private Logger $logger;

    /** Initializes the logger and factory shared by task creation tests. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger(true, true, true);
        $this->factory = new TaskFactory($this->logger);
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testDeleteTask(): void
    {
        // Arrange
        $taskConfig = [
            'type'   => 'delete',
            'params' => ['path' => 'dummyfile.txt'],
        ];
        // Action
        $task = $this->factory->create($taskConfig);
        // Assert
        $this->assertInstanceOf(DeleteTask::class, $task);
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testCreateDirectoryTask(): void
    {
        // Arrange
        $taskConfig = [
            'type'   => 'create_directory',
            'params' => ['name'  => 'dummyDir'],
        ];
        // Action
        $task = $this->factory->create($taskConfig);
        // Assert
        $this->assertInstanceOf(CreateDirectoryTask::class, $task);
        $this->assertSame('create_directory', $task->getType());
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testCopyTask(): void
    {
        // Arrange
        $taskConfig = [
            'type'   => 'copy',
            'params' => ['source' => 'dummySource', 'dest' => 'dummyDest'],
        ];
        // Action
        $task = $this->factory->create($taskConfig);
        // Assert
        $this->assertInstanceOf(CopyTask::class, $task);
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testExecTask(): void
    {
        // Arrange
        $taskConfig = [
            'type'   => 'exec',
            'params' => ['command' => 'echo Hello'],
        ];
        // Action
        $task = $this->factory->create($taskConfig);
        // Assert
        $this->assertInstanceOf(ExecTask::class, $task);
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testEchoTask(): void
    {
        // Arrange
        $taskConfig = [
            'type'   => 'echo',
            'params' => ['message' => 'Hello World'],
        ];
        // Action
        $task = $this->factory->create($taskConfig);
        // Assert
        $this->assertInstanceOf(EchoTask::class, $task);
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testZipTask(): void
    {
        // Arrange
        $taskConfig = [
            'type'   => 'archive',
            'params' => ['source' => 'dummySource', 'output' => 'dummyOutput.zip'],
        ];
        // Action
        $task = $this->factory->create($taskConfig);
        // Assert
        $this->assertInstanceOf(ZipTask::class, $task);
    }

    /**
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function testUnknownTaskTypeThrowsException(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown task type 'invalid' in build configuration.");
        // Arrange
        $taskConfig = [
            'type'   => 'invalid',
            'params' => [],
        ];
        // Action
        $this->factory->create($taskConfig);
    }

    /** Rejects a missing task type before attempting task construction. */
    public function testCreateRejectsMissingTaskType(): void
    {
        // Arrange
        $taskConfig = ['params' => []];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Task configuration requires a non-empty string 'type'.");

        // Act
        $this->factory->create($taskConfig);
    }

    /** Rejects malformed task parameters instead of passing them to a task. */
    public function testCreateRejectsNonArrayParameters(): void
    {
        // Arrange
        $taskConfig = ['type' => 'echo', 'params' => 'invalid'];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameters for task 'echo' must be an array.");

        // Act
        $this->factory->create($taskConfig);
    }
}
