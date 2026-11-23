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

    /** Creates the task fixture and its temporary project root. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-base-task-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root));
        $this->task = new BaseTaskTestProbe(new Logger(false, true, true));
    }

    /** Removes the temporary project root after each test. */
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
            /** Simulates a task implementation that throws during execution. */
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

    /** Debug mode records exception context in the log without changing task failure behavior. */
    public function testBaseTaskLogsExceptionDiagnosticsWhenDebugIsRequested(): void
    {
        // Arrange
        $logFile = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
        $task = new class (new Logger(true, true, false, $logFile)) extends BaseTaskTestProbe {
            /** Simulates a task failure while debug logging is enabled. */
            protected function runTask(bool $debug, bool $silent): bool
            {
                throw new \RuntimeException('Expected task failure');
            }
        };

        // Act
        $result = $task->execute(true, true);

        // Assert
        $log = file_get_contents($logFile);
        self::assertFalse($result);
        self::assertIsString($log);
        self::assertStringContainsString('DEBUG: Exception: RuntimeException', $log);
        self::assertStringContainsString('Location: ', $log);
        self::assertStringContainsString('Stack trace:', $log);
        self::assertStringContainsString(__FILE__, $log);
        self::assertStringContainsString('ERROR: Expected task failure', $log);
    }

    /** Normal task failures do not persist stack traces when debug mode is disabled. */
    public function testBaseTaskOmitsExceptionDiagnosticsWithoutDebug(): void
    {
        // Arrange
        $logFile = $this->root . DIRECTORY_SEPARATOR . 'normal.log';
        $task = new class (new Logger(false, true, false, $logFile)) extends BaseTaskTestProbe {
            /** Simulates a task failure while debug logging is disabled. */
            protected function runTask(bool $debug, bool $silent): bool
            {
                throw new \RuntimeException('Expected task failure');
            }
        };

        // Act
        $result = $task->execute(false, true);

        // Assert
        $log = file_get_contents($logFile);
        self::assertFalse($result);
        self::assertIsString($log);
        self::assertStringContainsString('ERROR: Expected task failure', $log);
        self::assertStringNotContainsString('Stack trace:', $log);
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

    /** Detects directory links through the shared filesystem implementation. */
    public function testDetectsDirectoryLinks(): void
    {
        // Arrange
        $target = $this->root . DIRECTORY_SEPARATOR . 'link-target';
        $link = $this->root . DIRECTORY_SEPARATOR . 'directory-link';
        self::assertTrue(mkdir($target));
        $this->createDirectoryLink($target, $link);

        try {
            // Act
            $isLink = $this->task->isLinkOrJunction($link);

            // Assert
            self::assertTrue($isLink);
            self::assertDirectoryExists($target);
        } finally {
            if (FilesystemTree::isLink($link)) {
                $this->removeDirectoryLink($link);
            }
        }
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

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('project root');

        // Act
        $this->task->assertPathIsSafe($root, 'test path', false);
    }

    /** Rejects empty and null-byte-containing paths. */
    public function testRejectsInvalidPathStrings(): void
    {
        // Arrange
        $path = "invalid\0path";

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or protected path');

        // Act
        $this->task->assertPathIsSafe($path, 'test path');
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
    /** Creates the probe task with the supplied logger. */
    public function __construct(Logger $logger)
    {
        parent::__construct([], $logger);
    }

    /** Exposes the base task link check to unit tests. */
    public function isLinkOrJunction(string $path): bool
    {
        return $this->isSymbolicLinkOrJunction($path);
    }

    /** Exposes the base task path-safety validation to unit tests. */
    public function assertPathIsSafe(string $path, string $label, bool $allowProjectRoot = true): void
    {
        $this->assertSafePath($path, $label, $allowProjectRoot);
    }

    /** Exposes filesystem-order path resolution to unit tests. */
    public function resolvePath(string $path, string $label): string
    {
        return $this->resolvePathInFilesystemOrder($path, $label);
    }

    /** Exposes safe link removal to unit tests. */
    public function removeLinkOrJunction(string $path): bool
    {
        return $this->removeSymbolicLinkOrJunction($path);
    }

    /** Returns a successful result for the probe task. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        return true;
    }

    /** Accepts the empty parameter set used by the test probe. */
    protected function validateParams(): void {}
}
