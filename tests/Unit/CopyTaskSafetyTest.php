<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\CopyTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests transactional file-copy behavior and filesystem alias protection. */
final class CopyTaskSafetyTest extends TestCase
{
    private string $root;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->root = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-copy-safety-' . bin2hex(random_bytes(6));
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
        if (!function_exists('link')) {
            self::markTestSkipped('Hardlink creation is unavailable.');
        }
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $destination = $this->root . DIRECTORY_SEPARATOR . 'destination.txt';
        self::assertSame(11, file_put_contents($source, 'preserved!'));
        if (!@link($source, $destination)) {
            self::markTestSkipped('The current filesystem does not support hardlinks.');
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
}
