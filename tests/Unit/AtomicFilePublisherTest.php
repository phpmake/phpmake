<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\AtomicFilePublisher;
use PHPUnit\Framework\TestCase;

/** Tests same-directory publication and replacement of staged files. */
final class AtomicFilePublisherTest extends TestCase
{
    /** Publishes a staged file when the destination does not yet exist. */
    public function testPublishCreatesDestination(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-publish-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $staged = $directory . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $directory . DIRECTORY_SEPARATOR . 'result.txt';
        file_put_contents($staged, 'new content');

        try {
            // Act
            AtomicFilePublisher::publish($staged, $destination);

            // Assert
            self::assertFileDoesNotExist($staged);
            self::assertSame('new content', file_get_contents($destination));
        } finally {
            if (is_file($staged)) {
                unlink($staged);
            }

            if (is_file($destination)) {
                unlink($destination);
            }

            rmdir($directory);
        }
    }

    /** Replaces an existing destination only after the staged file is ready. */
    public function testPublishReplacesExistingDestination(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-publish-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $staged = $directory . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $directory . DIRECTORY_SEPARATOR . 'result.txt';
        file_put_contents($staged, 'replacement');
        file_put_contents($destination, 'original');

        try {
            // Act
            AtomicFilePublisher::publish($staged, $destination);

            // Assert
            self::assertFileDoesNotExist($staged);
            self::assertSame('replacement', file_get_contents($destination));
            self::assertSame([], glob($directory . DIRECTORY_SEPARATOR . '.phpmake-backup-*') ?: []);
        } finally {
            if (is_file($staged)) {
                unlink($staged);
            }

            if (is_file($destination)) {
                unlink($destination);
            }

            foreach (glob($directory . DIRECTORY_SEPARATOR . '.phpmake-backup-*') ?: [] as $backup) {
                unlink($backup);
            }

            rmdir($directory);
        }
    }

    /** Rejects a directory destination without moving or deleting its contents. */
    public function testPublishRejectsDirectoryDestination(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-publish-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $staged = $directory . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $directory . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($destination));
        file_put_contents($staged, 'new content');
        file_put_contents($destination . DIRECTORY_SEPARATOR . 'keep.txt', 'preserve');

        try {
            // Act
            try {
                AtomicFilePublisher::publish($staged, $destination);
                self::fail('A directory cannot be replaced by a staged file.');
            } catch (\InvalidArgumentException $invalidArgumentException) {
                // Assert
                self::assertSame('Existing destination must be a regular file.', $invalidArgumentException->getMessage());
            }

            self::assertSame('new content', file_get_contents($staged));
            self::assertSame('preserve', file_get_contents($destination . DIRECTORY_SEPARATOR . 'keep.txt'));
        } finally {
            if (is_file($staged)) {
                unlink($staged);
            }

            if (is_file($destination . DIRECTORY_SEPARATOR . 'keep.txt')) {
                unlink($destination . DIRECTORY_SEPARATOR . 'keep.txt');
            }

            if (is_dir($destination)) {
                rmdir($destination);
            }

            rmdir($directory);
        }
    }

    /** Rejects a staged symbolic link without publishing its target contents. */
    public function testPublishRejectsStagedSymbolicLink(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-publish-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $target = $directory . DIRECTORY_SEPARATOR . 'target.txt';
        $staged = $directory . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $directory . DIRECTORY_SEPARATOR . 'result.txt';
        file_put_contents($target, 'target content');
        if (!@symlink($target, $staged)) {
            self::markTestSkipped('Symbolic-link creation is unavailable in this environment.');
        }

        try {
            // Act
            try {
                AtomicFilePublisher::publish($staged, $destination);
                self::fail('A staged symbolic link must not be published.');
            } catch (\InvalidArgumentException $invalidArgumentException) {
                // Assert
                self::assertStringContainsString('regular file', $invalidArgumentException->getMessage());
            }

            self::assertFileExists($staged);
            self::assertFileDoesNotExist($destination);
            self::assertSame('target content', file_get_contents($target));
        } finally {
            if (is_link($staged)) {
                unlink($staged);
            }

            if (is_file($target)) {
                unlink($target);
            }

            if (is_file($destination)) {
                unlink($destination);
            }

            rmdir($directory);
        }
    }

    /** Rejects a destination symbolic link without touching its target. */
    public function testPublishRejectsDestinationSymbolicLink(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-publish-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $target = $directory . DIRECTORY_SEPARATOR . 'target.txt';
        $staged = $directory . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $directory . DIRECTORY_SEPARATOR . 'destination-link';
        file_put_contents($target, 'target content');
        file_put_contents($staged, 'new content');
        if (!@symlink($target, $destination)) {
            self::markTestSkipped('Symbolic-link creation is unavailable in this environment.');
        }

        try {
            // Act
            try {
                AtomicFilePublisher::publish($staged, $destination);
                self::fail('A destination symbolic link must not be replaced.');
            } catch (\InvalidArgumentException $invalidArgumentException) {
                // Assert
                self::assertStringContainsString('symbolic link', $invalidArgumentException->getMessage());
            }

            self::assertSame('new content', file_get_contents($staged));
            self::assertSame('target content', file_get_contents($target));
            self::assertTrue(is_link($destination));
        } finally {
            if (is_link($destination)) {
                unlink($destination);
            }

            if (is_file($staged)) {
                unlink($staged);
            }

            if (is_file($target)) {
                unlink($target);
            }

            rmdir($directory);
        }
    }
    /** Rejects a staged path that does not identify a regular file. */
    public function testPublishRejectsMissingStagedFile(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-publish-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $staged = $directory . DIRECTORY_SEPARATOR . 'missing.tmp';
        $destination = $directory . DIRECTORY_SEPARATOR . 'result.txt';
        try {
            // Assert
            $this->expectException(\InvalidArgumentException::class);
            // Act
            AtomicFilePublisher::publish($staged, $destination);
        } finally {
            rmdir($directory);
        }
    }

    /** Rejects using the same filesystem entry as staged file and destination. */
    public function testPublishRejectsSameStagedFileAndDestination(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-publish-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $path = $directory . DIRECTORY_SEPARATOR . 'same.txt';
        self::assertSame(4, file_put_contents($path, 'keep'));
        try {
            // Assert
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('different filesystem entries');
            // Act
            AtomicFilePublisher::publish($path, $path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /** Rejects staged and destination files located in different directories. */
    public function testPublishRejectsDifferentParentDirectories(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-publish-' . bin2hex(random_bytes(6));
        $stagingDirectory = $directory . DIRECTORY_SEPARATOR . 'staging';
        self::assertTrue(mkdir($stagingDirectory, 0777, true));
        $staged = $stagingDirectory . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $directory . DIRECTORY_SEPARATOR . 'result.txt';
        self::assertSame(3, file_put_contents($staged, 'new'));
        try {
            // Assert
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('must share a directory');
            // Act
            AtomicFilePublisher::publish($staged, $destination);
        } finally {
            if (is_file($staged)) {
                unlink($staged);
            }
            rmdir($stagingDirectory);
            rmdir($directory);
        }
    }

}
