<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\CopyTask;
use PhpMake\Tests\Support\TemporaryDirectoryTestHelper;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests transactional file-copy behavior and filesystem alias protection. */
final class CopyTaskSafetyTest extends TestCase
{
    use TemporaryDirectoryTestHelper;

    private string $root;

    private string $originalWorkingDirectory;

    private Logger $logger;

    /** Creates an isolated working directory and logger for each test. */
    protected function setUp(): void
    {
        parent::setUp();
        // Use the system temp volume: the repository checkout may not support
        // hardlinks (notably Windows CI). Make it the task's project root so
        // BaseTask's project-directory safety boundary remains enforced.
        $this->originalWorkingDirectory = (string) getcwd();
        $this->root = $this->createTemporaryDirectory('.phpmake-copy-safety-');
        self::assertTrue(chdir($this->root));
        $this->logger = new Logger(false, true, true);
    }

    /** Restores the working directory and removes test fixtures. */
    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        $this->removeTemporaryDirectory($this->root);
        parent::tearDown();
    }

    /** Rejects a hardlink alias instead of truncating the source inode. */
    public function testCopyToHardlinkAliasDoesNotTruncateSource(): void
    {
        // Arrange
        if (!function_exists('link')) {
            self::markTestSkipped('Hardlink creation is unavailable in this PHP runtime.');
        }

        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination.txt';
        self::assertSame(10, file_put_contents($source, 'preserved!'));
        if (!@link($source, $destination)) {
            self::markTestSkipped('The system temporary filesystem does not support hardlinks.');
        }

        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
        self::assertSame('preserved!', file_get_contents($source));
        self::assertSame('preserved!', file_get_contents($destination));
    }

    /** Replaces an existing destination only after the staged copy is complete. */
    public function testCopyReplacesExistingFileAndCleansStagingFiles(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination.txt';
        self::assertSame(3, file_put_contents($source, 'new'));
        self::assertSame(3, file_put_contents($destination, 'old'));
        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertTrue($result);
        self::assertSame('new', file_get_contents($destination));
        self::assertSame('new', file_get_contents($source));
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.phpmake-copy-*') ?: []);
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.phpmake-copy-backup-*') ?: []);
    }


    /** Rejects a destination that contains the source tree. */
    public function testCopyDirectoryRejectsDestinationAncestorOfSource(): void
    {
        // Arrange
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination';
        $source = $destination . DIRECTORY_SEPARATOR . 'source';
        self::assertTrue(mkdir($source, 0777, true));
        self::assertSame(4, file_put_contents($source . DIRECTORY_SEPARATOR . 'keep.txt', 'keep'));

        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
        self::assertSame('keep', file_get_contents($source . DIRECTORY_SEPARATOR . 'keep.txt'));
        self::assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'keep.txt');
        self::assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '*.staging') ?: []);
    }

    /** Rejects a file destination link without modifying its target. */
    public function testCopyFileRejectsDestinationSymbolicLink(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('File symbolic-link creation is not assumed on Windows.');
        }

        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $target = $this->root . DIRECTORY_SEPARATOR . 'target.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination-link.txt';
        self::assertSame(6, file_put_contents($source, 'source'));
        self::assertSame(6, file_put_contents($target, 'target'));
        if (!@symlink($target, $destination)) {
            self::markTestSkipped('File symbolic-link creation is unavailable in this environment.');
        }

        try {
            $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

            // Act
            $result = $task->execute(false, true);

            // Assert
            self::assertFalse($result);
            self::assertSame('source', file_get_contents($source));
            self::assertSame('target', file_get_contents($target));
        } finally {
            if (is_link($destination)) {
                unlink($destination);
            }
        }
    }

    /** Rejects special filesystem entries that are neither regular files nor directories. */
    public function testCopyTaskRejectsNamedPipeSource(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_mkfifo')) {
            self::markTestSkipped('Named-pipe creation is not available on this platform.');
        }

        $source = $this->root . DIRECTORY_SEPARATOR . 'source.pipe';
        self::assertTrue(posix_mkfifo($source, 0600));
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination.txt';

        try {
            $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

            // Act
            $result = $task->execute(false, true);

            // Assert
            self::assertFalse($result);
            self::assertFileDoesNotExist($destination);
        } finally {
            if (file_exists($source)) {
                unlink($source);
            }
        }
    }

}
