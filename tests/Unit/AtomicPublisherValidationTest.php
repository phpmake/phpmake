<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\AtomicDirectoryPublisher;
use PhpMake\Filesystem\AtomicFilePublisher;
use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PHPUnit\Framework\TestCase;

/** Covers rejected publication inputs without mutating existing filesystem entries. */
final class AtomicPublisherValidationTest extends TestCase
{
    use DirectoryLinkTestHelper;

    private string $base;

    /** Creates a temporary directory for publication validation cases. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-publisher-validation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0777, true));
    }

    /** Removes the temporary publication fixtures after each test. */
    protected function tearDown(): void
    {
        FilesystemTree::remove($this->base);
        parent::tearDown();
    }

    /** Rejects a missing staged file before attempting publication. */
    public function testFilePublisherRejectsMissingStagedFile(): void
    {
        // Arrange
        $staged = $this->base . DIRECTORY_SEPARATOR . 'missing.tmp';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'result.txt';

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        // Act
        AtomicFilePublisher::publish($staged, $destination);
    }

    /** Rejects publishing a staged file onto the same filesystem entry. */
    public function testFilePublisherRejectsSameStagedAndDestinationPath(): void
    {
        // Arrange
        $path = $this->base . DIRECTORY_SEPARATOR . 'same.txt';
        self::assertSame(4, file_put_contents($path, 'keep'));

        // Act / Assert
        try {
            AtomicFilePublisher::publish($path, $path);
            self::fail('A staged file must not replace itself.');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('different filesystem entries', $invalidArgumentException->getMessage());
        }

        // Assert
        self::assertSame('keep', file_get_contents($path));
    }

    /** Rejects a staged file that is not beside the destination. */
    public function testFilePublisherRejectsDifferentParentDirectories(): void
    {
        // Arrange
        $stagingDirectory = $this->base . DIRECTORY_SEPARATOR . 'staging';
        self::assertTrue(mkdir($stagingDirectory));
        $staged = $stagingDirectory . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'result.txt';
        self::assertSame(3, file_put_contents($staged, 'new'));

        // Act / Assert
        try {
            AtomicFilePublisher::publish($staged, $destination);
            self::fail('Publication across parent directories must be rejected.');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('share a directory', $invalidArgumentException->getMessage());
        }

        // Assert
        self::assertSame('new', file_get_contents($staged));
        self::assertFileDoesNotExist($destination);
    }

    /** Rejects a missing staged directory before publication. */
    public function testDirectoryPublisherRejectsMissingStagingDirectory(): void
    {
        // Arrange
        $staging = $this->base . DIRECTORY_SEPARATOR . 'missing';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        AtomicDirectoryPublisher::publish($staging, $destination);
    }

    /** Rejects a staged directory link without following its target. */
    public function testDirectoryPublisherRejectsStagingDirectoryLink(): void
    {
        // Arrange
        $target = $this->base . DIRECTORY_SEPARATOR . 'target';
        $staging = $this->base . DIRECTORY_SEPARATOR . 'staging-link';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($target));
        self::assertSame(4, file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'keep'));
        $this->createDirectoryLink($target, $staging);

        try {
            // Act / Assert
            try {
                AtomicDirectoryPublisher::publish($staging, $destination);
                self::fail('A staged directory link must not be published.');
            } catch (\InvalidArgumentException $invalidArgumentException) {
                self::assertStringContainsString('must not be a symbolic link', $invalidArgumentException->getMessage());
            }

            // Assert
            self::assertDirectoryDoesNotExist($destination);
            self::assertSame('keep', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
        } finally {
            if (FilesystemTree::isLink($staging)) {
                $this->removeDirectoryLink($staging);
            }
        }
    }

    /** Rejects a staged file link without reading through it. */
    public function testFilePublisherRejectsStagedFileLink(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('File symbolic-link creation is not assumed on Windows.');
        }

        $target = $this->base . DIRECTORY_SEPARATOR . 'target.txt';
        $staged = $this->base . DIRECTORY_SEPARATOR . 'staged-link.txt';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination.txt';
        self::assertSame(4, file_put_contents($target, 'keep'));
        if (!@symlink($target, $staged)) {
            self::markTestSkipped('File symbolic-link creation is unavailable.');
        }

        try {
            // Assert
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Staged path must be a regular file');

            // Act
            AtomicFilePublisher::publish($staged, $destination);
        } finally {
            if (is_link($staged)) {
                unlink($staged);
            }
        }
    }

    /** Rejects a destination file link without modifying its target. */
    public function testFilePublisherRejectsDestinationFileLink(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('File symbolic-link creation is not assumed on Windows.');
        }

        $staged = $this->base . DIRECTORY_SEPARATOR . 'staged.txt';
        $target = $this->base . DIRECTORY_SEPARATOR . 'target.txt';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination-link.txt';
        self::assertSame(3, file_put_contents($staged, 'new'));
        self::assertSame(4, file_put_contents($target, 'keep'));
        if (!@symlink($target, $destination)) {
            self::markTestSkipped('File symbolic-link creation is unavailable.');
        }

        try {
            // Act
            try {
                AtomicFilePublisher::publish($staged, $destination);
                self::fail('A destination file link must not be replaced.');
            } catch (\InvalidArgumentException $invalidArgumentException) {
                // Assert
                self::assertStringContainsString('Destination must not be a symbolic link', $invalidArgumentException->getMessage());
            }

            // Assert
            self::assertSame('new', file_get_contents($staged));
            self::assertSame('keep', file_get_contents($target));
        } finally {
            if (is_link($destination)) {
                unlink($destination);
            }
        }
    }

    /** Rejects a directory where a regular destination file is required. */
    public function testFilePublisherRejectsDirectoryDestination(): void
    {
        // Arrange
        $staged = $this->base . DIRECTORY_SEPARATOR . 'staged.txt';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertSame(3, file_put_contents($staged, 'new'));
        self::assertTrue(mkdir($destination));

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Existing destination must be a regular file.');

        // Act
        AtomicFilePublisher::publish($staged, $destination);
    }

    /** Rejects a directory link as the destination without writing into its target. */
    public function testDirectoryPublisherRejectsDestinationDirectoryLink(): void
    {
        // Arrange
        $staging = $this->base . DIRECTORY_SEPARATOR . 'staging';
        $target = $this->base . DIRECTORY_SEPARATOR . 'target';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination-link';
        self::assertTrue(mkdir($staging));
        self::assertTrue(mkdir($target));
        self::assertSame(3, file_put_contents($staging . DIRECTORY_SEPARATOR . 'new.txt', 'new'));
        $this->createDirectoryLink($target, $destination);

        try {
            // Act
            try {
                AtomicDirectoryPublisher::publish($staging, $destination);
                self::fail('A destination directory link must not be replaced.');
            } catch (\InvalidArgumentException $invalidArgumentException) {
                self::assertStringContainsString('Destination must not be a symbolic link', $invalidArgumentException->getMessage());
            }

            // Assert
            self::assertFileDoesNotExist($target . DIRECTORY_SEPARATOR . 'new.txt');
        } finally {
            if (FilesystemTree::isLink($destination)) {
                $this->removeDirectoryLink($destination);
            }
        }
    }

    /** Rejects a regular file where a destination directory is required. */
    public function testDirectoryPublisherRejectsFileDestination(): void
    {
        // Arrange
        $staging = $this->base . DIRECTORY_SEPARATOR . 'staging';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($staging));
        self::assertSame(7, file_put_contents($destination, 'blocker'));

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Existing destination must be a directory.');

        // Act
        AtomicDirectoryPublisher::publish($staging, $destination);
    }

    /** Reports a failed publication and preserves the staged file when its parent is not writable. */
    public function testFilePublisherReportsRenameFailureWithoutExistingDestination(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory permission semantics are not available on Windows.');
        }

        $staged = $this->base . DIRECTORY_SEPARATOR . 'staged.tmp';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination.txt';
        self::assertSame(3, file_put_contents($staged, 'new'));
        self::assertTrue(chmod($this->base, 0500));

        try {
            // Act
            try {
                AtomicFilePublisher::publish($staged, $destination);
                self::fail('Publication must fail when the parent directory is not writable.');
            } catch (\RuntimeException $runtimeException) {
                // Assert
                self::assertStringContainsString('Unable to publish file', $runtimeException->getMessage());
            }

            // Assert
            self::assertFileDoesNotExist($destination);
            self::assertSame('new', file_get_contents($staged));
        } finally {
            chmod($this->base, 0700);
        }
    }

}
