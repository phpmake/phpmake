<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\BaseTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests the shared task contract and base-class behavior. */
final class BaseTaskTest extends TestCase
{
    private string $root;

    private BaseTaskTestProbe $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-base-task-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root));
        $this->task = new BaseTaskTestProbe(new Logger(false, true, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }

        @rmdir($this->root);
        parent::tearDown();
    }

    /** The base implementation returns the concrete task class as its type. */
    public function testBaseTaskReturnsConcreteClassNameByDefault(): void
    {
        // Arrange
        $task = new BaseTaskTestProbe(new Logger(false, true, true));

        // Act
        $type = $task->getType();
        $result = $task->execute(false, true);

        // Assert
        self::assertSame(BaseTaskTestProbe::class, $type);
        self::assertTrue($result);
    }

    /** Exceptions thrown by task implementations are converted to a failed result. */
    public function testBaseTaskConvertsExecutionExceptionToFailure(): void
    {
        // Arrange
        $task = new class (new Logger(false, true, true)) extends BaseTaskTestProbe {
            protected function runTask(bool $debug, bool $silent): bool
            {
                throw new \RuntimeException('Expected task failure');
            }
        };

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
    }

    /** Existing regular files and directories are not classified as links. */
    public function testDetectsRegularFilesystemEntriesAsNonLinks(): void
    {
        // Arrange
        $file = $this->root . DIRECTORY_SEPARATOR . 'file.txt';
        $directory = $this->root . DIRECTORY_SEPARATOR . 'directory';
        self::assertSame(7, file_put_contents($file, 'content'));
        self::assertTrue(mkdir($directory));

        // Act
        $fileIsLink = $this->task->isLinkOrJunction($file);
        $directoryIsLink = $this->task->isLinkOrJunction($directory);
        $missingIsLink = $this->task->isLinkOrJunction($this->root . DIRECTORY_SEPARATOR . 'missing');

        // Assert
        self::assertFalse($fileIsLink);
        self::assertFalse($directoryIsLink);
        self::assertFalse($missingIsLink);
    }

    /** Accepts an existing path contained by the current project directory. */
    public function testAcceptsPathInsideProject(): void
    {
        // Arrange
        $file = $this->root . DIRECTORY_SEPARATOR . 'inside.txt';
        self::assertSame(6, file_put_contents($file, 'inside'));

        // Act
        $this->task->assertPathIsSafe($file, 'test path');

        // Assert
        self::assertFileExists($file);
    }

    /** Rejects the project root when a destructive operation disallows it. */
    public function testRejectsProjectRootWhenNotAllowed(): void
    {
        // Arrange
        $root = getcwd();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('project root');

        // Act
        $this->task->assertPathIsSafe($root, 'test path', false);
    }

    /** Rejects empty and null-byte-containing paths. */
    public function testRejectsInvalidPathStrings(): void
    {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or protected path');

        // Act
        $this->task->assertPathIsSafe("invalid\0path", 'test path');
    }

    /** Resolves parent components in filesystem order after resolving existing directories. */
    public function testResolvesExistingParentComponentsInFilesystemOrder(): void
    {
        // Arrange
        $nested = $this->root . DIRECTORY_SEPARATOR . 'nested';
        $target = $this->root . DIRECTORY_SEPARATOR . 'target.txt';
        self::assertTrue(mkdir($nested));
        self::assertSame(6, file_put_contents($target, 'target'));
        $path = $nested . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'target.txt';

        // Act
        $resolved = $this->task->resolvePath($path, 'test path');

        // Assert
        self::assertSame(realpath($target), $resolved);
    }

    /** Rejects parent traversal when an earlier path component cannot be resolved. */
    public function testRejectsUnresolvedComponentBeforeParentTraversal(): void
    {
        // Arrange
        $path = $this->root . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'target';

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unresolved path component before '..'");

        // Act
        $this->task->resolvePath($path, 'test path');
    }
}

/** Exposes protected BaseTask path helpers for focused unit tests. */
class BaseTaskTestProbe extends BaseTask
{
    public function __construct(Logger $logger)
    {
        parent::__construct([], $logger);
    }

    public function isLinkOrJunction(string $path): bool
    {
        return $this->isSymbolicLinkOrJunction($path);
    }

    public function assertPathIsSafe(string $path, string $label, bool $allowProjectRoot = true): void
    {
        $this->assertSafePath($path, $label, $allowProjectRoot);
    }

    public function resolvePath(string $path, string $label): string
    {
        return $this->resolvePathInFilesystemOrder($path, $label);
    }

    protected function runTask(bool $debug, bool $silent): bool
    {
        return true;
    }

    protected function validateParams(): void
    {
    }
}
