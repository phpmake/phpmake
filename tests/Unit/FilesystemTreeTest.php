<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PHPUnit\Framework\TestCase;

/** Covers recursive filesystem copying and cleanup without link traversal. */
final class FilesystemTreeTest extends TestCase
{
    use DirectoryLinkTestHelper;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-filesystem-tree-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0777, true));
    }

    protected function tearDown(): void
    {
        FilesystemTree::remove($this->base);
        parent::tearDown();
    }

    /** Copies nested files and preserves their relative directory structure. */
    public function testCopyDirectoryCopiesNestedFiles(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($source . DIRECTORY_SEPARATOR . 'nested', 0777, true));
        self::assertSame(5, file_put_contents($source . DIRECTORY_SEPARATOR . 'root.txt', 'root!'));
        self::assertSame(6, file_put_contents($source . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child.txt', 'child!'));

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertTrue($copied);
        self::assertSame('root!', file_get_contents($destination . DIRECTORY_SEPARATOR . 'root.txt'));
        self::assertSame('child!', file_get_contents($destination . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child.txt'));
    }

    /** Rejects a directory link instead of copying content from its target. */
    public function testCopyDirectoryRejectsDirectoryLinks(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $outside = $this->base . DIRECTORY_SEPARATOR . 'outside';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($source));
        self::assertTrue(mkdir($outside));
        self::assertSame(6, file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt', 'secret'));
        $link = $source . DIRECTORY_SEPARATOR . 'linked';
        $this->createDirectoryLink($outside, $link);

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertFalse($copied);
        self::assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'linked' . DIRECTORY_SEPARATOR . 'secret.txt');
        self::assertSame('secret', file_get_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt'));
    }

    /** Removes a directory link itself and leaves its target untouched. */
    public function testRemoveDeletesDirectoryLinkWithoutTraversingTarget(): void
    {
        // Arrange
        $target = $this->base . DIRECTORY_SEPARATOR . 'target';
        $link = $this->base . DIRECTORY_SEPARATOR . 'link';
        self::assertTrue(mkdir($target));
        self::assertSame(4, file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'keep'));
        $this->createDirectoryLink($target, $link);

        // Act
        $removed = FilesystemTree::remove($link);

        // Assert
        self::assertTrue($removed);
        self::assertDirectoryExists($target);
        self::assertSame('keep', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
        self::assertFalse(is_link($link));
        self::assertFalse(is_dir($link));
    }

    /** Refuses empty paths and the current working directory. */
    public function testRemoveRejectsProtectedPaths(): void
    {
        // Arrange
        $currentDirectory = getcwd();

        // Act
        $emptyPathRemoved = FilesystemTree::remove('');
        $currentDirectoryRemoved = FilesystemTree::remove($currentDirectory);

        // Assert
        self::assertFalse($emptyPathRemoved);
        self::assertFalse($currentDirectoryRemoved);
        self::assertDirectoryExists($currentDirectory);
    }

    /** Treats a missing path as already removed. */
    public function testRemoveReturnsTrueForMissingPath(): void
    {
        // Arrange
        $missing = $this->base . DIRECTORY_SEPARATOR . 'missing';

        // Act
        $removed = FilesystemTree::remove($missing);

        // Assert
        self::assertTrue($removed);
    }
}
