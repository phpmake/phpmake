<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Task\BaseTask;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests the shared task contract and base-class behavior. */
final class BaseTaskTest extends TestCase
{
    use DirectoryLinkTestHelper;
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
        FilesystemTree::remove($this->root);
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
    /** Resolves a missing leaf without treating it as an existing filesystem entry. */
    public function testResolvesMissingLeafWithinProject(): void
    {
        // Arrange
        $missing = $this->root . DIRECTORY_SEPARATOR . 'missing.txt';

        // Act
        $resolved = $this->task->resolvePath($missing, 'test path');

        // Assert
        self::assertStringEndsWith('missing.txt', $resolved);
        self::assertStringStartsWith(realpath($this->root), $resolved);
    }

    /** Rejects parent traversal when a preceding component is a regular file. */
    public function testRejectsNonDirectoryComponentBeforeParentTraversal(): void
    {
        // Arrange
        $file = $this->root . DIRECTORY_SEPARATOR . 'not-a-directory';
        self::assertSame(4, file_put_contents($file, 'data'));
        $path = $file . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'target';

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Non-directory path component');

        // Act
        $this->task->resolvePath($path, 'test path');
    }

    /** Rejects dangling links before using a path that cannot be resolved. */
    public function testRejectsDanglingDirectoryLink(): void
    {
        // Arrange
        $target = $this->root . DIRECTORY_SEPARATOR . 'link-target';
        $movedTarget = $this->root . DIRECTORY_SEPARATOR . 'moved-target';
        $link = $this->root . DIRECTORY_SEPARATOR . 'dangling-link';
        self::assertTrue(mkdir($target));
        $this->createDirectoryLink($target, $link);
        self::assertTrue(rename($target, $movedTarget));

        try {
            // Assert
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Dangling or unresolvable link');

            // Act
            $this->task->resolvePath($link . DIRECTORY_SEPARATOR . 'child', 'test path');
        } finally {
            if (\PhpMake\Filesystem\FilesystemTree::isLink($link)) {
                $this->removeDirectoryLink($link);
            }
        }
    }

    /** Removes a directory link itself without deleting its target. */
    public function testRemovesDirectoryLinkWithoutTraversingTarget(): void
    {
        // Arrange
        $target = $this->root . DIRECTORY_SEPARATOR . 'remove-target';
        $link = $this->root . DIRECTORY_SEPARATOR . 'remove-link';
        self::assertTrue(mkdir($target));
        self::assertSame(4, file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'keep'));
        $this->createDirectoryLink($target, $link);

        try {
            // Act
            $removed = $this->task->removeLinkOrJunction($link);

            // Assert
            self::assertTrue($removed);
            self::assertDirectoryExists($target);
            self::assertSame('keep', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
            self::assertFalse(\PhpMake\Filesystem\FilesystemTree::isLink($link));
        } finally {
            if (\PhpMake\Filesystem\FilesystemTree::isLink($link)) {
                $this->removeDirectoryLink($link);
            }
        }
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

    public function removeLinkOrJunction(string $path): bool
    {
        return $this->removeSymbolicLinkOrJunction($path);
    }

    protected function runTask(bool $debug, bool $silent): bool
    {
        return true;
    }

    protected function validateParams(): void {}
}
