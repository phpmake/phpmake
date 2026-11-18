<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Exercises filesystem task behavior against real temporary files and directories. */
final class FilesystemTaskCoverageTest extends TestCase
{
    private Logger $logger;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger(false, true, true);
        $this->root = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-fs-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root));
    }

    protected function tearDown(): void
    {
        $this->removePath($this->root);
        parent::tearDown();
    }

    /** Copies a file and creates missing destination directories. */
    public function testCopyTaskCopiesFileAndCreatesDestinationDirectory(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'copy.txt';
        file_put_contents($source, 'copy payload');

        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        self::assertSame('copy', $task->getType());
        self::assertTrue($task->execute(false, true));
        self::assertFileExists($destination);
        self::assertSame('copy payload', file_get_contents($destination));
    }

    /** Recursively copies nested directories and preserves relative paths and contents. */
    public function testCopyTaskCopiesNestedDirectoryTree(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source . DIRECTORY_SEPARATOR . 'nested', 0777, true);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'root.txt', 'root');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child.txt', 'child');
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination';

        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        self::assertTrue($task->execute(false, true));
        self::assertSame('root', file_get_contents($destination . DIRECTORY_SEPARATOR . 'root.txt'));
        self::assertSame('child', file_get_contents($destination . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child.txt'));
    }

    /** Missing copy sources fail without creating a destination. */
    public function testCopyTaskReturnsFalseForMissingSource(): void
    {
        $destination = $this->root . DIRECTORY_SEPARATOR . 'missing.txt';
        $task = new CopyTask([
            'source' => $this->root . DIRECTORY_SEPARATOR . 'absent.txt',
            'dest' => $destination,
        ], $this->logger);

        self::assertFalse($task->execute(false, true));
        self::assertFileDoesNotExist($destination);
    }

    /** Copy fails cleanly when a regular file blocks destination-directory creation. */
    public function testCopyTaskReturnsFalseWhenDestinationParentIsAFile(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $blocker = $this->root . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($source, 'payload');
        file_put_contents($blocker, 'not a directory');
        $task = new CopyTask([
            'source' => $source,
            'dest' => $blocker . DIRECTORY_SEPARATOR . 'copy.txt',
        ], $this->logger);

        self::assertFalse($task->execute(false, true));
        self::assertSame('not a directory', file_get_contents($blocker));
    }

    /** A source symlink is rejected rather than followed. */
    public function testCopyTaskRejectsSourceSymlink(): void
    {
        $target = $this->root . DIRECTORY_SEPARATOR . 'target.txt';
        $link = $this->root . DIRECTORY_SEPARATOR . 'link.txt';
        file_put_contents($target, 'target');
        if (!@symlink($target, $link)) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }

        $task = new CopyTask([
            'source' => $link,
            'dest' => $this->root . DIRECTORY_SEPARATOR . 'copy.txt',
        ], $this->logger);

        self::assertFalse($task->execute(false, true));
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'copy.txt');
    }

    /** A failed copy operation is reported when the destination itself is a directory. */
    public function testCopyTaskReturnsFalseWhenDestinationIsDirectory(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination';
        file_put_contents($source, 'payload');
        mkdir($destination);
        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        self::assertFalse($task->execute(false, true));
        self::assertDirectoryExists($destination);
    }

    /** A directory copy fails cleanly when its destination path is occupied by a file. */
    public function testCopyTaskReturnsFalseWhenDirectoryDestinationIsAFile(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'item.txt', 'payload');
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination';
        file_put_contents($destination, 'blocker');
        $task = new CopyTask(['source' => $source, 'dest' => $destination], $this->logger);

        self::assertFalse($task->execute(false, true));
        self::assertSame('blocker', file_get_contents($destination));
    }

    /** Deletes a file and treats an already absent path as a successful no-op. */
    public function testDeleteTaskDeletesFileAndIsIdempotent(): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'remove.txt';
        file_put_contents($file, 'remove');

        $task = new DeleteTask(['path' => $file], $this->logger);
        self::assertSame('delete', $task->getType());
        self::assertTrue($task->execute(false, true));
        self::assertFileDoesNotExist($file);

        $missing = new DeleteTask(['path' => $file], $this->logger);
        self::assertTrue($missing->execute(false, true));
    }

    /** Recursively removes nested files and directories. */
    public function testDeleteTaskDeletesNestedDirectoryTree(): void
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'tree';
        mkdir($directory . DIRECTORY_SEPARATOR . 'nested', 0777, true);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'root.txt', 'root');
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child.txt', 'child');

        $task = new DeleteTask(['path' => $directory], $this->logger);

        self::assertTrue($task->execute(false, true));
        self::assertDirectoryDoesNotExist($directory);
    }

    /** A symlink used as the archive source is rejected rather than followed. */
    public function testZipTaskRejectsSymlinkSource(): void
    {
        if (!class_exists(\\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is unavailable.');
        }
        $target = $this->root . DIRECTORY_SEPARATOR . 'target.txt';
        $link = $this->root . DIRECTORY_SEPARATOR . 'source-link.txt';
        file_put_contents($target, 'target');
        if (!@symlink($target, $link)) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }
        $task = new ZipTask([
            'source' => $link,
            'output' => $this->root . DIRECTORY_SEPARATOR . 'link.zip',
        ], $this->logger);

        self::assertFalse($task->execute(false, true));
    }

    /** Archives a single file and verifies the stored entry and payload. */
    public function testZipTaskArchivesSingleFile(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is unavailable.');
        }
        $source = $this->root . DIRECTORY_SEPARATOR . 'single.txt';
        $archive = $this->root . DIRECTORY_SEPARATOR . 'single.zip';
        file_put_contents($source, 'archive payload');

        $task = new ZipTask(['source' => $source, 'output' => $archive], $this->logger);

        self::assertSame('archive', $task->getType());
        self::assertTrue($task->execute(false, true));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
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
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is unavailable.');
        }
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source . DIRECTORY_SEPARATOR . 'nested', 0777, true);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'item.txt', 'nested payload');
        $archive = $this->root . DIRECTORY_SEPARATOR . 'tree.zip';

        $task = new ZipTask(['source' => $source, 'output' => $archive], $this->logger);

        self::assertTrue($task->execute(false, true));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
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
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is unavailable.');
        }
        $archive = $this->root . DIRECTORY_SEPARATOR . 'missing.zip';
        $task = new ZipTask([
            'source' => $this->root . DIRECTORY_SEPARATOR . 'missing',
            'output' => $archive,
        ], $this->logger);

        self::assertFalse($task->execute(false, true));
        self::assertFileDoesNotExist($archive);
    }

    /** Archive creation fails when its output parent is a regular file. */
    public function testZipTaskFailsWhenOutputParentIsAFile(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is unavailable.');
        }
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $blocker = $this->root . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($source, 'payload');
        file_put_contents($blocker, 'not a directory');
        $task = new ZipTask([
            'source' => $source,
            'output' => $blocker . DIRECTORY_SEPARATOR . 'archive.zip',
        ], $this->logger);

        self::assertFalse($task->execute(false, true));
        self::assertSame('not a directory', file_get_contents($blocker));
    }

    /** A symlink encountered during recursive archive traversal aborts and removes the partial archive. */
    public function testZipTaskRejectsNestedSymlinkAndCleansPartialArchive(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is unavailable.');
        }
        $source = $this->root . DIRECTORY_SEPARATOR . 'source';
        mkdir($source);
        file_put_contents($source . DIRECTORY_SEPARATOR . 'before.txt', 'before');
        $target = $this->root . DIRECTORY_SEPARATOR . 'target.txt';
        file_put_contents($target, 'target');
        if (!@symlink($target, $source . DIRECTORY_SEPARATOR . 'linked.txt')) {
            self::markTestSkipped('Symlink creation is unavailable.');
        }
        $archive = $this->root . DIRECTORY_SEPARATOR . 'partial.zip';

        $task = new ZipTask(['source' => $source, 'output' => $archive], $this->logger);

        self::assertFalse($task->execute(false, true));
        self::assertFileDoesNotExist($archive);
    }

    /** Removes temporary files without following symbolic links. */
    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->removePath($path . DIRECTORY_SEPARATOR . $item);
                }
            }
        }
        @rmdir($path);
    }
}
