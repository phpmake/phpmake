<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\AtomicDirectoryPublisher;
use PhpMake\Filesystem\FilesystemTree;
use PHPUnit\Framework\TestCase;

/** Covers same-parent directory publication and preservation of previous content. */
final class AtomicDirectoryPublisherTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-atomic-directory-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0777, true));
    }

    protected function tearDown(): void
    {
        FilesystemTree::remove($this->base);
        parent::tearDown();
    }

    /** Publishes a staged directory when no destination exists. */
    public function testPublishCreatesDestination(): void
    {
        // Arrange
        $staging = $this->base . DIRECTORY_SEPARATOR . 'staging';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($staging));
        self::assertSame(3, file_put_contents($staging . DIRECTORY_SEPARATOR . 'new.txt', 'new'));

        // Act
        AtomicDirectoryPublisher::publish($staging, $destination);

        // Assert
        self::assertDirectoryExists($destination);
        self::assertSame('new', file_get_contents($destination . DIRECTORY_SEPARATOR . 'new.txt'));
        self::assertDirectoryDoesNotExist($staging);
    }

    /** Replaces an existing directory and removes its backup after publication. */
    public function testPublishReplacesExistingDestination(): void
    {
        // Arrange
        $staging = $this->base . DIRECTORY_SEPARATOR . 'staging';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($staging));
        self::assertTrue(mkdir($destination));
        self::assertSame(3, file_put_contents($staging . DIRECTORY_SEPARATOR . 'new.txt', 'new'));
        self::assertSame(3, file_put_contents($destination . DIRECTORY_SEPARATOR . 'old.txt', 'old'));

        // Act
        AtomicDirectoryPublisher::publish($staging, $destination);

        // Assert
        self::assertFileExists($destination . DIRECTORY_SEPARATOR . 'new.txt');
        self::assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'old.txt');
        self::assertDirectoryDoesNotExist($staging);
        $entries = scandir($this->base);
        self::assertSame(['.', '..', 'destination'], $entries);
    }

    /** Rejects a staging directory on a different parent path. */
    public function testPublishRejectsDifferentParentDirectories(): void
    {
        // Arrange
        $stagingParent = $this->base . DIRECTORY_SEPARATOR . 'staging-parent';
        $destinationParent = $this->base . DIRECTORY_SEPARATOR . 'destination-parent';
        self::assertTrue(mkdir($stagingParent));
        self::assertTrue(mkdir($destinationParent));
        $staging = $stagingParent . DIRECTORY_SEPARATOR . 'staging';
        self::assertTrue(mkdir($staging));
        $destination = $destinationParent . DIRECTORY_SEPARATOR . 'destination';

        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
        AtomicDirectoryPublisher::publish($staging, $destination);
    }
}
