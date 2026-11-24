<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Verifies that local log paths cannot redirect writes through links or special files. */
final class LoggerSecurityTest extends TestCase
{
    private string $previousDirectory;

    private string $temporaryDirectory;

    /** Creates an isolated working directory for local log path tests. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDirectory = getcwd();
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-logger-security-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0777, true));
        self::assertTrue(chdir($this->temporaryDirectory));
    }

    /** Restores the working directory and removes only test-owned entries. */
    protected function tearDown(): void
    {
        chdir($this->previousDirectory);
        if (!FilesystemTree::remove($this->temporaryDirectory)) {
            throw new \\RuntimeException(sprintf('Unable to remove logger security test directory "%s".', $this->temporaryDirectory));
        }
        parent::tearDown();
    }

    /** Refuses symlink log and lock paths without modifying their targets. */
    public function testLoggerDoesNotFollowSymbolicLogOrLockFiles(): void
    {
        // Arrange
        $target = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'protected.txt';
        self::assertSame(8, file_put_contents($target, 'original'));
        if (!@symlink($target, 'linked.log')) {
            self::markTestSkipped('File symbolic-link creation is unavailable.');
        }

        if (!@symlink($target, 'blocked.log.lock')) {
            self::markTestSkipped('File symbolic-link creation is unavailable.');
        }

        // Act
        (new Logger(false, true, false, 'linked.log'))->info('must-not-append');
        (new Logger(false, true, false, 'blocked.log'))->info('must-not-append');

        // Assert
        self::assertSame('original', file_get_contents($target));
        self::assertTrue(is_link('linked.log'));
        self::assertTrue(is_link('blocked.log.lock'));
        self::assertFileDoesNotExist('blocked.log');
    }

    /** Refuses hardlinked log paths so writes cannot alter another file name. */
    public function testLoggerDoesNotAppendThroughHardlinkedLogFile(): void
    {
        // Arrange
        $target = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'protected.txt';
        self::assertSame(8, file_put_contents($target, 'original'));
        if (!@link($target, 'linked.log')) {
            self::markTestSkipped('Hardlink creation is unavailable.');
        }

        // Act
        (new Logger(false, true, false, 'linked.log'))->info('must-not-append');

        // Assert
        self::assertSame('original', file_get_contents($target));
        self::assertSame('original', file_get_contents('linked.log'));
    }

    /** Refuses a FIFO lock path before opening it, avoiding a blocking read/write open. */
    public function testLoggerDoesNotOpenSpecialFileAsLock(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_mkfifo')) {
            self::markTestSkipped('POSIX FIFO creation is unavailable on this platform.');
        }

        self::assertTrue(posix_mkfifo('blocked.log.lock', 0600));

        // Act
        (new Logger(false, true, false, 'blocked.log'))->info('must-not-write');

        // Assert
        self::assertFileDoesNotExist('blocked.log');
        self::assertTrue(file_exists('blocked.log.lock'));
    }
}
