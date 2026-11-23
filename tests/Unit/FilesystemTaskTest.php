<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PhpMake\Tests\Support\TemporaryDirectoryTestHelper;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Exercises filesystem task behavior against real temporary files and directories. */
final class FilesystemTaskTest extends TestCase
{
    use DirectoryLinkTestHelper;
    use TemporaryDirectoryTestHelper;

    private Logger $logger;

    private string $root;

    private string $originalCwd;

    /** Initializes test fixtures. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger(false, true, true);
        $this->originalCwd = getcwd();
        // Keep the test workspace on the system volume. Windows directory
        // junctions require source and target to share a local NTFS volume.
        $this->root = $this->createTemporaryDirectory('.phpmake-fs-');
        self::assertTrue(chdir($this->root));
    }

    /** Cleans up test fixtures. */
    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeTemporaryDirectory($this->root);
        parent::tearDown();
    }

    /** Copies a file and creates missing destination directories. */
    public function testCopyTaskCopiesFileAndCreatesDestinationDirectory(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'copy.txt';
        file_put_contents($source, 'copy payload');

        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        // Assert
        // Act
        self::assertSame('copy', $task->getType());
        self::assertTrue($task->execute(false, true));
        self::assertFileExists($destination);
        self::assertSame('copy payload', file_get_contents($destination));
    }

    /** Recursively copies nested directories and preserves relative paths and contents. */
    public function testCopyTaskCopiesNestedDirectoryTree(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source . DIRECTORY_SEPARATOR . 'nested', 0777, true);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'root.txt', 'root');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child.txt', 'child');
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination';

        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        // Assert
        // Act
        self::assertTrue($task->execute(false, true));
        self::assertSame('root', file_get_contents($destination . DIRECTORY_SEPARATOR . 'root.txt'));
        self::assertSame('child', file_get_contents($destination . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child.txt'));
    }

    /** Missing copy sources fail without creating a destination. */
    public function testCopyTaskReturnsFalseForMissingSource(): void
    {
        // Arrange
        $destination = $this->root . DIRECTORY_SEPARATOR . 'missing.txt';
        $task = new CopyTask([
            'source' => $this->root . DIRECTORY_SEPARATOR . 'absent.txt',
            'dest' => $destination,
        ], $this->logger);

        // Assert
        // Act
        self::assertFalse($task->execute(false, true));
        self::assertFileDoesNotExist($destination);
    }

    /** Copy fails cleanly when a regular file blocks destination-directory creation. */
    public function testCopyTaskReturnsFalseWhenDestinationParentIsAFile(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $blocker = $this->root . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($source, 'payload');
        file_put_contents($blocker, 'not a directory');
        $task = new CopyTask([
            'source' => $source,
            'dest' => $blocker . DIRECTORY_SEPARATOR . 'copy.txt',
        ], $this->logger);

        // Assert
        // Act
        $archived = $task->execute(false, true);

        // Assert
        self::assertFalse($archived);
        self::assertSame('not a directory', file_get_contents($blocker));
    }

    /** A source symlink is rejected rather than followed. */
    public function testCopyTaskRejectsSourceSymlink(): void
    {
        // Arrange
        $target = $this->root . DIRECTORY_SEPARATOR . 'target';
        $link = $this->root . DIRECTORY_SEPARATOR . 'link';
        mkdir($target);
        file_put_contents($target . DIRECTORY_SEPARATOR . 'target.txt', 'target');
        $this->createDirectoryLink($target, $link);

        $task = new CopyTask([
            'source' => $link,
            'dest' => $this->root . DIRECTORY_SEPARATOR . 'copy',
        ], $this->logger);

        // Act
        $copied = $task->execute(false, true);

        // Assert
        self::assertFalse($copied);
        self::assertDirectoryDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'copy');
    }

    /** A failed copy operation is reported when the destination itself is a directory. */
    public function testCopyTaskReturnsFalseWhenDestinationIsDirectory(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination';
        file_put_contents($source, 'payload');
        mkdir($destination);
        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        // Assert
        // Act
        self::assertFalse($task->execute(false, true));
        self::assertDirectoryExists($destination);
    }

    /** A directory copy fails cleanly when its destination path is occupied by a file. */
    public function testCopyTaskReturnsFalseWhenDirectoryDestinationIsAFile(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'item.txt', 'payload');
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination';
        file_put_contents($destination, 'blocker');
        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        // Assert
        // Act
        self::assertFalse($task->execute(false, true));
        self::assertSame('blocker', file_get_contents($destination));
    }

    /** Rejects a destination junction instead of writing through it. */
    public function testCopyTaskRejectsDestinationDirectoryLink(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        $target = $this->root . DIRECTORY_SEPARATOR . 'target';
        $link = $this->root . DIRECTORY_SEPARATOR . 'destination-link';
        mkdir($source);
        mkdir($target);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'source.txt', 'source');
        file_put_contents($target . DIRECTORY_SEPARATOR . 'target.txt', 'preserve');
        $this->createDirectoryLink($target, $link);

        $task = new CopyTask(['source' => $source, 'dest' => $link], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
        self::assertSame('preserve', file_get_contents($target . DIRECTORY_SEPARATOR . 'target.txt'));
        self::assertFileDoesNotExist($target . DIRECTORY_SEPARATOR . 'source.txt');
    }

    /** Deletes a file and treats an already absent path as a successful no-op. */
    public function testDeleteTaskDeletesFileAndIsIdempotent(): void
    {
        // Arrange
        $file = $this->root . DIRECTORY_SEPARATOR . 'remove.txt';
        file_put_contents($file, 'remove');

        $task = new DeleteTask(['path' => $file], $this->logger);
        // Assert
        // Act
        self::assertSame('delete', $task->getType());
        self::assertTrue($task->execute(false, true));
        self::assertFileDoesNotExist($file);

        $missing = new DeleteTask(['path' => $file], $this->logger);
        self::assertTrue($missing->execute(false, true));
    }

    /** Recursively removes nested files and directories. */
    public function testDeleteTaskDeletesNestedDirectoryTree(): void
    {
        // Arrange
        $directory = $this->root . DIRECTORY_SEPARATOR . 'tree';
        mkdir($directory . DIRECTORY_SEPARATOR . 'nested', 0777, true);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'root.txt', 'root');
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child.txt', 'child');

        $task = new DeleteTask(['path' => $directory], $this->logger);

        // Assert
        // Act
        self::assertTrue($task->execute(false, true));
        self::assertDirectoryDoesNotExist($directory);
    }


    /** Deletes a directory link itself without traversing its target. */
    public function testDeleteTaskDeletesDirectoryLinkWithoutTouchingTarget(): void
    {
        // Arrange
        $target = $this->root . DIRECTORY_SEPARATOR . 'delete-target';
        $link = $this->root . DIRECTORY_SEPARATOR . 'delete-link';
        self::assertTrue(mkdir($target));
        self::assertSame(4, file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'keep'));
        $this->createDirectoryLink($target, $link);
        $task = new DeleteTask(['path' => $link], $this->logger);

        // Act
        $deleted = $task->execute(false, true);

        // Assert
        self::assertTrue($deleted);
        self::assertDirectoryExists($target);
        self::assertSame('keep', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
        self::assertFalse(FilesystemTree::isLink($link));
        self::assertFalse(file_exists($link));
    }

    /** A symlink used as the archive source is rejected rather than followed. */
    public function testZipTaskRejectsSymlinkSource(): void
    {
        // Arrange
        $target = $this->root . DIRECTORY_SEPARATOR . 'target';
        $link = $this->root . DIRECTORY_SEPARATOR . 'source-link';
        mkdir($target);
        file_put_contents($target . DIRECTORY_SEPARATOR . 'target.txt', 'target');
        $this->createDirectoryLink($target, $link);
        $task = new ZipTask([
            'source' => $link,
            'output' => $this->root . DIRECTORY_SEPARATOR . 'link.zip',
        ], $this->logger);

        // Act
        $archived = $task->execute(false, true);

        // Assert
        self::assertFalse($archived);
    }

    /** Archives a single file and verifies the stored entry and payload. */
    public function testZipTaskArchivesSingleFile(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'single.txt';
        $archive = $this->root . DIRECTORY_SEPARATOR . 'single.zip';
        file_put_contents($source, 'archive payload');

        $task = new ZipTask(['source' => $source, 'output' => $archive], $this->logger);

        // Act
        $type = $task->getType();
        $archived = $task->execute(false, true);
        $zip = new \ZipArchive();
        $opened = $zip->open($archive);

        // Assert
        self::assertSame('archive', $type);
        self::assertTrue($archived);
        self::assertTrue($opened);
        try {
            self::assertSame(1, $zip->numFiles);
            self::assertSame('single.txt', $zip->getNameIndex(0));
            self::assertSame('archive payload', $zip->getFromName('single.txt'));
        } finally {
            $zip->close();
        }
    }

    /** Archives nested files with normalized relative entry names. */
    public function testZipTaskArchivesNestedDirectoryTree(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source . DIRECTORY_SEPARATOR . 'nested', 0777, true);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'item.txt', 'nested payload');
        $archive = $this->root . DIRECTORY_SEPARATOR . 'tree.zip';

        $task = new ZipTask(['source' => $source, 'output' => $archive], $this->logger);

        // Act
        $archived = $task->execute(false, true);
        $zip = new \ZipArchive();
        $opened = $zip->open($archive);

        // Assert
        self::assertTrue($archived);
        self::assertTrue($opened);
        try {
            self::assertSame('nested/item.txt', $zip->getNameIndex(0));
            self::assertSame('nested payload', $zip->getFromName('nested/item.txt'));
        } finally {
            $zip->close();
        }
    }

    /** Missing archive sources fail and do not leave an archive behind. */
    public function testZipTaskRejectsMissingSourceAtExecution(): void
    {
        // Arrange
        $archive = $this->root . DIRECTORY_SEPARATOR . 'missing.zip';
        $task = new ZipTask([
            'source' => $this->root . DIRECTORY_SEPARATOR . 'missing',
            'output' => $archive,
        ], $this->logger);

        // Act
        $archived = $task->execute(false, true);

        // Assert
        self::assertFalse($archived);
        self::assertFileDoesNotExist($archive);
    }

    /** Archive creation fails when its output parent is a regular file. */
    public function testZipTaskFailsWhenOutputParentIsAFile(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $blocker = $this->root . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($source, 'payload');
        file_put_contents($blocker, 'not a directory');
        $task = new ZipTask([
            'source' => $source,
            'output' => $blocker . DIRECTORY_SEPARATOR . 'archive.zip',
        ], $this->logger);

        // Act
        $archived = $task->execute(false, true);

        // Assert
        self::assertFalse($archived);
        self::assertSame('not a directory', file_get_contents($blocker));
    }

    /** A symlink encountered during recursive archive traversal aborts and removes the partial archive. */
    public function testZipTaskRejectsNestedSymlinkAndCleansPartialArchive(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'before.txt', 'before');
        $target = $this->root . DIRECTORY_SEPARATOR . 'target';
        mkdir($target);
        file_put_contents($target . DIRECTORY_SEPARATOR . 'target.txt', 'target');
        $this->createDirectoryLink($target, $source . DIRECTORY_SEPARATOR . 'linked');
        $archive = $this->root . DIRECTORY_SEPARATOR . 'partial.zip';

        $task = new ZipTask(['source' => $source, 'output' => $archive], $this->logger);

        // Act
        $archived = $task->execute(false, true);

        // Assert
        self::assertFalse($archived);
        self::assertFileDoesNotExist($archive);
    }

    /** Copy task rejects malformed parameter types during construction. */
    public function testCopyTaskRejectsNonStringPaths(): void
    {
        // Arrange
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        // Act
        new CopyTask(['source' => 123, 'dest' => 'destination.txt'], $this->logger);
    }

    /** Delete task rejects a missing or non-string path during construction. */
    public function testDeleteTaskRejectsNonStringPath(): void
    {
        // Arrange
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        // Act
        new DeleteTask(['path' => 123], $this->logger);
    }

    /** Archive task rejects malformed source and output parameter types. */
    public function testZipTaskRejectsNonStringPaths(): void
    {
        // Arrange
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        // Act
        new ZipTask(['source' => 'source.txt', 'output' => 123], $this->logger);
    }

    /** Prevents archiving a file onto itself before ZipArchive can truncate it. */
    public function testZipTaskRejectsOutputThatWouldOverwriteSourceFile(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'same-file.txt';
        file_put_contents($source, 'must remain intact');
        $task = new ZipTask(['source' => $source, 'output' => $source], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
        self::assertSame('must remain intact', file_get_contents($source));
    }

    /** Rejects copies into the source directory itself or one of its descendants. */
    public function testCopyTaskRejectsDestinationInsideSourceDirectory(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'original.txt', 'preserve');
        $destination = $source . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'copy';

        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
        self::assertFileExists($source . DIRECTORY_SEPARATOR . 'original.txt');
        self::assertDirectoryDoesNotExist($source . DIRECTORY_SEPARATOR . 'nested');
    }

    /** Rejects a destination that aliases the source directory itself. */
    public function testCopyTaskRejectsDestinationEqualToSourceDirectory(): void
    {
        // Arrange
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'original.txt', 'preserve');

        $task = new CopyTask(['source' => $source, 'dest' => $source], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
        self::assertSame('preserve', file_get_contents($source . DIRECTORY_SEPARATOR . 'original.txt'));
    }

}
