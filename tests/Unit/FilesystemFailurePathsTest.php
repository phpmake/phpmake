<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\AtomicDirectoryPublisher;
use PhpMake\Filesystem\AtomicFilePublisher;
use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Exercises filesystem failure paths using real POSIX directory permissions. */
final class FilesystemFailurePathsTest extends TestCase
{
    private string $base;

    private string $originalWorkingDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalWorkingDirectory = (string) getcwd();
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-failure-paths-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0777, true));
    }

    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        chmod($this->base, 0700);
        FilesystemTree::remove($this->base);
        parent::tearDown();
    }

    /** Keeps the old file and removes the staged file when a same-directory backup cannot be created. */
    public function testAtomicFilePublisherPreservesDestinationWhenBackupDirectoryIsNotWritable(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(true, 'POSIX directory permission semantics are not available on Windows.');
            return;
        }

        $staged = $this->base . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination.txt';
        self::assertSame(3, file_put_contents($staged, 'new'));
        self::assertSame(3, file_put_contents($destination, 'old'));
        self::assertTrue(chmod($this->base, 0500));

        try {
            // Act / Assert
            try {
                AtomicFilePublisher::publish($staged, $destination);
                self::fail('The publisher must reject a recovery path outside the destination directory.');
            } catch (\RuntimeException $runtimeException) {
                self::assertStringContainsString('Unable to create recovery path', $runtimeException->getMessage());
            }

            // Assert
            self::assertSame('old', file_get_contents($destination));
            self::assertFileExists($staged);
            self::assertSame('new', file_get_contents($staged));
        } finally {
            chmod($this->base, 0700);
        }
    }

    /** Preserves the old directory when it cannot be moved to a recovery backup. */
    public function testAtomicDirectoryPublisherPreservesDestinationWhenParentIsNotWritable(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(true, 'POSIX directory permission semantics are not available on Windows.');
            return;
        }

        $staging = $this->base . DIRECTORY_SEPARATOR . 'staging';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($staging));
        self::assertTrue(mkdir($destination));
        self::assertSame(3, file_put_contents($staging . DIRECTORY_SEPARATOR . 'new.txt', 'new'));
        self::assertSame(3, file_put_contents($destination . DIRECTORY_SEPARATOR . 'old.txt', 'old'));
        self::assertTrue(chmod($this->base, 0500));

        try {
            // Act / Assert
            try {
                AtomicDirectoryPublisher::publish($staging, $destination);
                self::fail('The publisher must fail when the destination parent cannot be modified.');
            } catch (\RuntimeException $runtimeException) {
                self::assertStringContainsString('Unable to preserve existing destination', $runtimeException->getMessage());
            }

            // Assert
            self::assertFileExists($staging . DIRECTORY_SEPARATOR . 'new.txt');
            self::assertSame('old', file_get_contents($destination . DIRECTORY_SEPARATOR . 'old.txt'));
        } finally {
            chmod($this->base, 0700);
        }
    }

    /** Returns false without damaging the existing destination when staging cannot be created. */
    public function testFilesystemTreeLeavesDestinationUntouchedWhenStagingCannotBeCreated(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(true, 'POSIX directory permission semantics are not available on Windows.');
            return;
        }

        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($source));
        self::assertTrue(mkdir($destination));
        self::assertSame(3, file_put_contents($source . DIRECTORY_SEPARATOR . 'new.txt', 'new'));
        self::assertSame(3, file_put_contents($destination . DIRECTORY_SEPARATOR . 'old.txt', 'old'));
        self::assertTrue(chmod($this->base, 0500));

        try {
            // Act
            $copied = FilesystemTree::copyDirectory($source, $destination);

            // Assert
            self::assertFalse($copied);
            self::assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'new.txt');
            self::assertSame('old', file_get_contents($destination . DIRECTORY_SEPARATOR . 'old.txt'));
        } finally {
            chmod($this->base, 0700);
        }
    }

    /** Reports a failed file deletion when the containing directory is not writable. */
    public function testDeleteTaskReportsFailureWhenParentDirectoryIsNotWritable(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::assertTrue(true, 'POSIX directory permission semantics are not available on Windows.');
            return;
        }

        self::assertTrue(chdir($this->base));
        $file = $this->base . DIRECTORY_SEPARATOR . 'preserve.txt';
        self::assertSame(4, file_put_contents($file, 'keep'));
        $task = new DeleteTask(['path' => $file], new Logger(false, true, true));
        self::assertTrue(chmod($this->base, 0500));

        try {
            // Act
            $deleted = $task->execute(false, true);

            // Assert
            self::assertFalse($deleted);
            self::assertSame('keep', file_get_contents($file));
        } finally {
            chmod($this->base, 0700);
            chdir($this->originalWorkingDirectory);
        }
    }
}
