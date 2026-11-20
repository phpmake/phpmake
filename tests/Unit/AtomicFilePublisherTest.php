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
}
