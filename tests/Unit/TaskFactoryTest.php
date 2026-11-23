<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\TaskFactory;
use PhpMake\Task\TaskDefinitionRegistry;
use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests creation of supported task types and rejection of invalid configurations. */
final class TaskFactoryTest extends TestCase
{
    private TaskFactory $factory;

    /** Initializes the logger and task factory used by each test. */
    protected function setUp(): void
    {
        parent::setUp();

        $logger = new Logger(true, true, true);
        $this->factory = new TaskFactory($logger);
    }

    /** Keeps registry identifiers, definitions, and concrete task classes synchronized. */
    public function testTaskDefinitionRegistryExposesConsistentBuiltInDefinitions(): void
    {
        // Arrange
        $expected = [
            'delete' => DeleteTask::class,
            'create_directory' => CreateDirectoryTask::class,
            'copy' => CopyTask::class,
            'exec' => ExecTask::class,
            'echo' => EchoTask::class,
            'archive' => ZipTask::class,
        ];

        // Act
        $types = TaskDefinitionRegistry::types();
        $definitions = TaskDefinitionRegistry::all();

        // Assert
        self::assertSame(array_keys($expected), $types);
        self::assertSame($types, array_keys($definitions));
        foreach ($expected as $type => $class) {
            self::assertSame($class, $definitions[$type]['class']);
            self::assertSame($definitions[$type], TaskDefinitionRegistry::find($type));
        }
        self::assertNull(TaskDefinitionRegistry::find('unknown'));
    }

    /** Creates a delete task from a valid task configuration. */
    public function testCreateBuildsDeleteTask(): void
    {
        // Arrange
        $config = [
            'type' => 'delete',
            'params' => ['path' => 'dummyfile.txt'],
        ];

        // Act
        $task = $this->factory->create($config);

        // Assert
        self::assertInstanceOf(DeleteTask::class, $task);
    }

    /** Creates a directory-creation task from a valid task configuration. */
    public function testCreateBuildsCreateDirectoryTask(): void
    {
        // Arrange
        $config = [
            'type' => 'create_directory',
            'params' => ['name' => 'dummyDir'],
        ];

        // Act
        $task = $this->factory->create($config);

        // Assert
        self::assertInstanceOf(CreateDirectoryTask::class, $task);
        self::assertSame('create_directory', $task->getType());
    }

    /** Creates a copy task from a valid task configuration. */
    public function testCreateBuildsCopyTask(): void
    {
        // Arrange
        $config = [
            'type' => 'copy',
            'params' => ['source' => 'dummySource', 'dest' => 'dummyDest'],
        ];

        // Act
        $task = $this->factory->create($config);

        // Assert
        self::assertInstanceOf(CopyTask::class, $task);
    }

    /** Creates an external-command task from a valid task configuration. */
    public function testCreateBuildsExecTask(): void
    {
        // Arrange
        $config = [
            'type' => 'exec',
            'params' => ['command' => 'echo Hello'],
        ];

        // Act
        $task = $this->factory->create($config);

        // Assert
        self::assertInstanceOf(ExecTask::class, $task);
    }

    /** Creates an echo task from a valid task configuration. */
    public function testCreateBuildsEchoTask(): void
    {
        // Arrange
        $config = [
            'type' => 'echo',
            'params' => ['message' => 'Hello World'],
        ];

        // Act
        $task = $this->factory->create($config);

        // Assert
        self::assertInstanceOf(EchoTask::class, $task);
    }

    /** Creates an archive task from a valid task configuration. */
    public function testCreateBuildsArchiveTask(): void
    {
        // Arrange
        $config = [
            'type' => 'archive',
            'params' => ['source' => 'dummySource', 'output' => 'dummyOutput.zip'],
        ];

        // Act
        $task = $this->factory->create($config);

        // Assert
        self::assertInstanceOf(ZipTask::class, $task);
    }

    /** Rejects an unsupported task type with a descriptive exception. */
    public function testCreateRejectsUnknownTaskType(): void
    {
        // Arrange
        $config = [
            'type' => 'invalid',
            'params' => [],
        ];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown task type 'invalid' in build configuration.");

        // Act
        $this->factory->create($config);
    }

    /** Rejects a task configuration that omits its required type. */
    public function testCreateRejectsMissingTaskType(): void
    {
        // Arrange
        $config = ['params' => []];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Task configuration requires a non-empty string 'type'.");

        // Act
        $this->factory->create($config);
    }

    /** Rejects task parameters that are not supplied as an array. */
    public function testCreateRejectsNonArrayTaskParameters(): void
    {
        // Arrange
        $config = ['type' => 'echo', 'params' => 'invalid'];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameters for task 'echo' must be an array.");

        // Act
        $this->factory->create($config);
    }
}
