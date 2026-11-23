<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PhpMake\Tests\Support\TemporaryDirectoryTestHelper;
use PHPUnit\Framework\TestCase;

/** Covers recursive filesystem copying and cleanup without link traversal. */
final class FilesystemTreeTest extends TestCase
{
    use DirectoryLinkTestHelper;
    use TemporaryDirectoryTestHelper;

    private string $base;

    /** Creates an isolated filesystem tree for each test. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->base = $this->createTemporaryDirectory('phpmake-filesystem-tree-');
    }

    /** Removes the isolated filesystem tree after each test. */
    protected function tearDown(): void
    {
        $this->removeTemporaryDirectory($this->base);
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

    /** Copies into an existing destination while preserving unrelated files. */
    public function testCopyDirectoryMergesExistingDestination(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($source));
        self::assertTrue(mkdir($destination));
        self::assertSame(3, file_put_contents($source . DIRECTORY_SEPARATOR . 'replace.txt', 'new'));
        self::assertSame(3, file_put_contents($destination . DIRECTORY_SEPARATOR . 'replace.txt', 'old'));
        self::assertSame(6, file_put_contents($destination . DIRECTORY_SEPARATOR . 'keep.txt', 'keep!!'));

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertTrue($copied);
        self::assertSame('new', file_get_contents($destination . DIRECTORY_SEPARATOR . 'replace.txt'));
        self::assertSame('keep!!', file_get_contents($destination . DIRECTORY_SEPARATOR . 'keep.txt'));
    }

    /** Rejects a destination directory link without writing into its target. */
    public function testCopyDirectoryRejectsDestinationDirectoryLinks(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $target = $this->base . DIRECTORY_SEPARATOR . 'target';
        $destinationLink = $this->base . DIRECTORY_SEPARATOR . 'destination-link';
        self::assertTrue(mkdir($source));
        self::assertTrue(mkdir($target));
        self::assertSame(6, file_put_contents($source . DIRECTORY_SEPARATOR . 'new.txt', 'source'));
        $this->createDirectoryLink($target, $destinationLink);

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destinationLink);

        // Assert
        self::assertFalse($copied);
        self::assertFileDoesNotExist($target . DIRECTORY_SEPARATOR . 'new.txt');
        self::assertDirectoryExists($target);
    }

    /** Removes nested directories and their files recursively. */
    public function testRemoveDeletesNestedDirectoryTree(): void
    {
        // Arrange
        $tree = $this->base . DIRECTORY_SEPARATOR . 'tree'
            . DIRECTORY_SEPARATOR . 'nested';
        self::assertTrue(mkdir($tree, 0777, true));
        self::assertSame(4, file_put_contents($tree . DIRECTORY_SEPARATOR . 'file.txt', 'data'));
        $root = dirname($tree);

        // Act
        $removed = FilesystemTree::remove($root);

        // Assert
        self::assertTrue($removed);
        self::assertDirectoryDoesNotExist($root);
    }

    /** Returns false when the source directory does not exist. */
    public function testCopyDirectoryRejectsMissingSource(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'missing';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertFalse($copied);
        self::assertDirectoryDoesNotExist($destination);
    }


    /** Rejects a destination nested inside its source before creating anything. */
    public function testCopyDirectoryRejectsDestinationInsideSource(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $source . DIRECTORY_SEPARATOR . 'nested-copy';
        self::assertTrue(mkdir($source));
        self::assertSame(4, file_put_contents($source . DIRECTORY_SEPARATOR . 'file.txt', 'data'));

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertFalse($copied);
        self::assertDirectoryDoesNotExist($destination);
        self::assertSame('data', file_get_contents($source . DIRECTORY_SEPARATOR . 'file.txt'));
        self::assertSame([], glob($this->base . DIRECTORY_SEPARATOR . '.phpmake-tree-*.staging') ?: []);
    }

    /** Rejects identical source and destination directories. */
    public function testCopyDirectoryRejectsSameSourceAndDestination(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        self::assertTrue(mkdir($source));
        self::assertSame(4, file_put_contents($source . DIRECTORY_SEPARATOR . 'file.txt', 'data'));

        // Act
        $copied = FilesystemTree::copyDirectory($source, $source);

        // Assert
        self::assertFalse($copied);
        self::assertSame('data', file_get_contents($source . DIRECTORY_SEPARATOR . 'file.txt'));
    }

    /** Rejects a destination that is an ancestor of the source. */
    public function testCopyDirectoryRejectsDestinationAncestorOfSource(): void
    {
        // Arrange
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        $source = $destination . DIRECTORY_SEPARATOR . 'source';
        self::assertTrue(mkdir($source, 0777, true));
        self::assertSame(4, file_put_contents($source . DIRECTORY_SEPARATOR . 'file.txt', 'data'));

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertFalse($copied);
        self::assertSame('data', file_get_contents($source . DIRECTORY_SEPARATOR . 'file.txt'));
        self::assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'file.txt');
    }

    /** Preserves an existing destination and removes staging after a partial-copy failure. */
    public function testCopyDirectoryRollsBackPartialCopyFailure(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        $outside = $this->base . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(mkdir($source));
        self::assertTrue(mkdir($destination));
        self::assertTrue(mkdir($outside));
        self::assertSame(8, file_put_contents($destination . DIRECTORY_SEPARATOR . 'keep.txt', 'original'));
        self::assertSame(3, file_put_contents($source . DIRECTORY_SEPARATOR . 'a-first.txt', 'new'));
        self::assertSame(6, file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt', 'secret'));
        $this->createDirectoryLink($outside, $source . DIRECTORY_SEPARATOR . 'z-link');

        try {
            // Act
            $copied = FilesystemTree::copyDirectory($source, $destination);

            // Assert
            self::assertFalse($copied);
            self::assertSame('original', file_get_contents($destination . DIRECTORY_SEPARATOR . 'keep.txt'));
            self::assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'a-first.txt');
            self::assertSame('secret', file_get_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt'));
            self::assertSame([], glob($this->base . DIRECTORY_SEPARATOR . '.phpmake-tree-*.staging') ?: []);
        } finally {
            if (is_link($source . DIRECTORY_SEPARATOR . 'z-link') || $this->isDirectoryJunction($source . DIRECTORY_SEPARATOR . 'z-link')) {
                $this->removeDirectoryLink($source . DIRECTORY_SEPARATOR . 'z-link');
            }
        }
    }

    /** Removes newly-created destination parents when copying fails. */
    public function testCopyDirectoryCleansCreatedParentsAfterFailure(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'new-parent'
            . DIRECTORY_SEPARATOR . 'destination';
        $outside = $this->base . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(mkdir($source));
        self::assertTrue(mkdir($outside));
        self::assertSame(6, file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt', 'secret'));
        $this->createDirectoryLink($outside, $source . DIRECTORY_SEPARATOR . 'linked');

        try {
            // Act
            $copied = FilesystemTree::copyDirectory($source, $destination);

            // Assert
            self::assertFalse($copied);
            self::assertDirectoryDoesNotExist(dirname($destination));
            self::assertSame('secret', file_get_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt'));
        } finally {
            if (is_link($source . DIRECTORY_SEPARATOR . 'linked') || $this->isDirectoryJunction($source . DIRECTORY_SEPARATOR . 'linked')) {
                $this->removeDirectoryLink($source . DIRECTORY_SEPARATOR . 'linked');
            }
        }
    }



    /** Rejects a destination reached through a symlinked ancestor of the source. */
    public function testCopyDirectoryRejectsSymlinkedDestinationAncestorAlias(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $sourceAlias = $this->base . DIRECTORY_SEPARATOR . 'source-alias';
        $destination = $sourceAlias . DIRECTORY_SEPARATOR . 'copy';
        self::assertTrue(mkdir($source));
        self::assertSame(4, file_put_contents($source . DIRECTORY_SEPARATOR . 'file.txt', 'data'));
        $this->createDirectoryLink($source, $sourceAlias);

        try {
            // Act
            $copied = FilesystemTree::copyDirectory($source, $destination);

            // Assert
            self::assertFalse($copied);
            self::assertDirectoryDoesNotExist($source . DIRECTORY_SEPARATOR . 'copy');
            self::assertSame('data', file_get_contents($source . DIRECTORY_SEPARATOR . 'file.txt'));
        } finally {
            if (is_link($sourceAlias) || $this->isDirectoryJunction($sourceAlias)) {
                $this->removeDirectoryLink($sourceAlias);
            }
        }
    }

    /** Preserves an existing file when a source directory collides with it. */
    public function testCopyDirectoryRejectsDirectoryFileCollisionWithoutChanges(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($source . DIRECTORY_SEPARATOR . 'collision', 0777, true));
        self::assertSame(4, file_put_contents($source . DIRECTORY_SEPARATOR . 'collision' . DIRECTORY_SEPARATOR . 'new.txt', 'data'));
        self::assertTrue(mkdir($destination));
        self::assertSame(8, file_put_contents($destination . DIRECTORY_SEPARATOR . 'collision', 'original'));

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertFalse($copied);
        self::assertSame('original', file_get_contents($destination . DIRECTORY_SEPARATOR . 'collision'));
        self::assertFileDoesNotExist($destination . DIRECTORY_SEPARATOR . 'collision' . DIRECTORY_SEPARATOR . 'new.txt');
        self::assertSame([], glob($this->base . DIRECTORY_SEPARATOR . '.phpmake-tree-*.staging') ?: []);
    }



    /** Detects and removes a dangling directory link without touching its former target. */
    public function testRemoveDetectsDanglingDirectoryLinks(): void
    {
        // Arrange
        $target = $this->base . DIRECTORY_SEPARATOR . 'target';
        $movedTarget = $this->base . DIRECTORY_SEPARATOR . 'moved-target';
        $link = $this->base . DIRECTORY_SEPARATOR . 'dangling-link';
        self::assertTrue(mkdir($target));
        self::assertSame(4, file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'keep'));
        $this->createDirectoryLink($target, $link);
        self::assertTrue(rename($target, $movedTarget));

        try {
            // Act
            $isLink = FilesystemTree::isLink($link);
            $removed = FilesystemTree::remove($link);

            // Assert
            self::assertTrue($isLink);
            self::assertTrue($removed);
            self::assertDirectoryExists($movedTarget);
            self::assertSame('keep', file_get_contents($movedTarget . DIRECTORY_SEPARATOR . 'keep.txt'));
            self::assertFalse(file_exists($link));
            self::assertFalse(is_link($link));
        } finally {
            if (FilesystemTree::isLink($link)) {
                $this->removeDirectoryLink($link);
            }
        }
    }



    /** Preserves a pre-existing destination parent when a staged copy fails. */
    public function testCopyDirectoryPreservesExistingParentAfterFailure(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $parent = $this->base . DIRECTORY_SEPARATOR . 'existing-parent';
        $destination = $parent . DIRECTORY_SEPARATOR . 'destination';
        $outside = $this->base . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(mkdir($source));
        self::assertTrue(mkdir($parent));
        self::assertTrue(mkdir($outside));
        self::assertSame(6, file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt', 'secret'));
        $this->createDirectoryLink($outside, $source . DIRECTORY_SEPARATOR . 'linked');

        try {
            // Act
            $copied = FilesystemTree::copyDirectory($source, $destination);

            // Assert
            self::assertFalse($copied);
            self::assertDirectoryExists($parent);
            self::assertDirectoryDoesNotExist($destination);
            self::assertSame('secret', file_get_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt'));
        } finally {
            if (is_link($source . DIRECTORY_SEPARATOR . 'linked') || $this->isDirectoryJunction($source . DIRECTORY_SEPARATOR . 'linked')) {
                $this->removeDirectoryLink($source . DIRECTORY_SEPARATOR . 'linked');
            }
        }
    }



    /** Creates missing destination parents and publishes the completed tree. */
    public function testCopyDirectoryCreatesMissingParentsOnSuccess(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'new-parent'
            . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($source));
        self::assertSame(4, file_put_contents($source . DIRECTORY_SEPARATOR . 'file.txt', 'data'));

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertTrue($copied);
        self::assertSame('data', file_get_contents($destination . DIRECTORY_SEPARATOR . 'file.txt'));
        self::assertSame([], glob(dirname($destination) . DIRECTORY_SEPARATOR . '.phpmake-tree-*.staging') ?: []);
    }

    /** Rejects empty and null-byte paths before touching the filesystem. */
    public function testCopyDirectoryRejectsEmptyAndNullBytePaths(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($source));

        // Act
        $emptySource = FilesystemTree::copyDirectory('', $destination);
        $emptyDestination = FilesystemTree::copyDirectory($source, '');
        $nullByteSource = FilesystemTree::copyDirectory('invalid' . chr(0) . 'source', $destination);
        $nullByteDestination = FilesystemTree::copyDirectory($source, 'invalid' . chr(0) . 'destination');

        // Assert
        self::assertFalse($emptySource);
        self::assertFalse($emptyDestination);
        self::assertFalse($nullByteSource);
        self::assertFalse($nullByteDestination);
        self::assertDirectoryDoesNotExist($destination);
    }

    /** Rejects a source directory link without copying content from its target. */
    public function testCopyDirectoryRejectsSourceDirectoryLink(): void
    {
        // Arrange
        $target = $this->base . DIRECTORY_SEPARATOR . 'target';
        $sourceLink = $this->base . DIRECTORY_SEPARATOR . 'source-link';
        $destination = $this->base . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($target));
        self::assertSame(4, file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'keep'));
        $this->createDirectoryLink($target, $sourceLink);

        try {
            // Act
            $copied = FilesystemTree::copyDirectory($sourceLink, $destination);

            // Assert
            self::assertFalse($copied);
            self::assertDirectoryDoesNotExist($destination);
            self::assertSame('keep', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
        } finally {
            if (FilesystemTree::isLink($sourceLink)) {
                $this->removeDirectoryLink($sourceLink);
            }
        }
    }

    /** Rejects a destination whose parent path is a regular file. */
    public function testCopyDirectoryRejectsFileAsDestinationParent(): void
    {
        // Arrange
        $source = $this->base . DIRECTORY_SEPARATOR . 'source';
        $parent = $this->base . DIRECTORY_SEPARATOR . 'blocker';
        $destination = $parent . DIRECTORY_SEPARATOR . 'destination';
        self::assertTrue(mkdir($source));
        self::assertSame(7, file_put_contents($parent, 'blocker'));

        // Act
        $copied = FilesystemTree::copyDirectory($source, $destination);

        // Assert
        self::assertFalse($copied);
        self::assertSame('blocker', file_get_contents($parent));
        self::assertFileDoesNotExist($destination);
    }

    /** Rejects a null-byte path before scanning or deleting filesystem entries. */
    public function testRemoveRejectsNullBytePath(): void
    {
        // Arrange
        $path = 'invalid' . chr(0) . 'path';

        // Act
        $removed = FilesystemTree::remove($path);

        // Assert
        self::assertFalse($removed);
    }

}
