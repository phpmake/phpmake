<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests safe directory creation using real filesystem paths. */
final class CreateDirectoryTaskTest extends TestCase
{
    private string $previousDirectory;

    private string $temporaryDirectory;

    /** Creates an isolated project directory for each test. */
    protected function setUp(): void
    {
        parent::setUp();
        $previousDirectory = getcwd();
        self::assertNotFalse($previousDirectory);
        $this->previousDirectory = $previousDirectory;
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-create-directory-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0777, true));
        self::assertTrue(chdir($this->temporaryDirectory));
    }

    /** Restores the working directory and removes the test project. */
    protected function tearDown(): void
    {
        chdir($this->previousDirectory);
        FilesystemTree::remove($this->temporaryDirectory);
        parent::tearDown();
    }

    /** Creates a nested directory and reports the built-in task identifier. */
    public function testCreatesNestedDirectory(): void
    {
        // Arrange
        $task = new CreateDirectoryTask(
            ['name' => 'nested/created'],
            new Logger(false, true, true)
        );

        // Act
        $type = $task->getType();
        $result = $task->execute(false, true);

        // Assert
        self::assertSame('create_directory', $type);
        self::assertTrue($result);
        self::assertDirectoryExists('nested/created');
    }

    /** Treats an existing directory as a successful no-op. */
    public function testExistingDirectoryIsSuccessful(): void
    {
        // Arrange
        self::assertTrue(mkdir('existing'));
        $task = new CreateDirectoryTask(
            ['name' => 'existing'],
            new Logger(true, true, true)
        );

        // Act
        $result = $task->execute(true, true);

        // Assert
        self::assertTrue($result);
        self::assertDirectoryExists('existing');
    }

    /** Reports failure when the requested directory path is occupied by a file. */
    public function testReturnsFalseWhenTargetIsAnExistingFile(): void
    {
        // Arrange
        self::assertSame(4, file_put_contents('blocked', 'file'));
        $task = new CreateDirectoryTask(
            ['name' => 'blocked'],
            new Logger(false, true, true)
        );

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
        self::assertSame('file', file_get_contents('blocked'));
    }

    /** Rejects a missing directory name during task construction. */
    public function testRejectsMissingName(): void
    {
        // Arrange
        $logger = new Logger(false, true, true);

        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing or invalid 'name' parameter");

        // Act
        new CreateDirectoryTask([], $logger);
    }
}
