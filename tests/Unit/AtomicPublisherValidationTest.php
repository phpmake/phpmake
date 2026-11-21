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

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-publisher-validation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0777, true));
    }

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

        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
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

        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
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
}
