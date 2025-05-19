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

final class TaskFactoryTest extends TestCase
{
    private TaskFactory $factory;

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
        $this->logger = new Logger(true, true, true);
        $this->factory = new TaskFactory($this->logger);
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
     * @test
     *
     * @small
     *
     * @return void
     */
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
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
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
     * @test
     *
     * @small
     *
     * @return void
     */
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
     * @test
     *
     * @small
     *
     * @return void
     */
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
     * @test
     *
     * @small
     *
     * @return void
     */
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
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testUnknownTaskTypeThrowsException(): void
    {
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Unknown task type 'invalid' in build configuration.");
        // Arrange
        $taskConfig = [
            'type'   => 'invalid',
            'params' => [],
        ];
        // Action
        $this->factory->create($taskConfig);
    }
}
