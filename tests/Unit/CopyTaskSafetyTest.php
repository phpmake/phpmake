<?php

declare(strict_types=1);

namespace PhpMake\\Tests\\Unit;

use PhpMake\\Task\\Types\\CopyTask;
use PhpMake\\Utilities\\Logger;
use PHPUnit\\Framework\\TestCase;

/** Tests transactional file-copy behavior and filesystem alias protection. */
final class CopyTaskSafetyTest extends TestCase
{
    private string $root;

    private Logger $logger;

    protected function setUp(): void
    {
        // Use the system temp volume: the repository checkout may be on a
        // filesystem that does not support hardlinks (notably Windows CI).
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '.phpmake-copy-safety-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root));
        $this->logger = new Logger(false, true, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }

        if (is_dir($this->root)) {
            @rmdir($this->root);
        }
    }

    /** Rejects a hardlink alias instead of truncating the source inode. */
    public function testCopyToHardlinkAliasDoesNotTruncateSource(): void
    {
        // Arrange
        self::assertTrue(function_exists('link'), 'Hardlink creation is unavailable in this PHP runtime.');

        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination.txt';
        self::assertSame(10, file_put_contents($source, 'preserved!'));
        self::assertTrue(
            @link($source, $destination),
            'The system temporary filesystem must support hardlinks for this safety test.'
        );

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
}
