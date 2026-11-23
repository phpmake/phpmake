<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PhpMake\Tests\Support\TemporaryDirectoryTestHelper;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests filesystem boundary enforcement and symbolic-link safety. */
final class PathSafetyTest extends TestCase
{
    use DirectoryLinkTestHelper;
    use TemporaryDirectoryTestHelper;

    private Logger $logger;

    private string $workspace;

    private string $originalWorkingDirectory;

    /** Initializes a silent logger for filesystem safety tests. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalWorkingDirectory = (string) getcwd();
        $this->workspace = $this->createTemporaryDirectory('phpmake-path-safety-');
        self::assertTrue(chdir($this->workspace));
        $this->logger = new Logger(false, true, true);
    }

    /** Restores the original working directory and removes all remaining fixtures. */
    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        $this->removeTemporaryDirectory($this->workspace);
        parent::tearDown();
    }

    /** Rejects a delete path outside the active project root. */
    public function testDeleteRejectsPathOutsideProject(): void
    {
        // Arrange
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new DeleteTask(['path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'outside.txt'], $this->logger);
    }

    /** Rejects a copy source outside the active project root. */
    public function testCopyRejectsSourceOutsideProject(): void
    {
        // Arrange
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new CopyTask([
            'source' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'src.txt',
            'dest'   => 'inside.txt',
        ], $this->logger);
    }

    /** Rejects directory creation that traverses above the project root. */
    public function testCreateDirectoryRejectsParentTraversal(): void
    {
        // Arrange
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new CreateDirectoryTask(['name' => '..' . DIRECTORY_SEPARATOR . 'outside-dir'], $this->logger);
    }

    /** Rejects an archive output path outside the active project root. */
    public function testArchiveRejectsOutputOutsideProject(): void
    {
        // Arrange
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new ZipTask([
            'source' => 'src',
            'output' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'out.zip',
        ], $this->logger);
    }

    /** Treats an existing destination directory as a successful create operation. */
    public function testCreateDirectoryIsIdempotent(): void
    {
        // Arrange
        $dir = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-test-idempotent-' . uniqid('', true);

        $task = new CreateDirectoryTask(['name' => $dir], $this->logger);

        // Act
        $created = $task->execute(false, true);

        // Assert
        $this->assertTrue($created);
        $this->assertDirectoryExists($dir);

        // Second run must succeed when directory already exists.
        $task2 = new CreateDirectoryTask(['name' => $dir], $this->logger);
        $secondExecution = $task2->execute(false, true);
        $this->assertTrue($secondExecution);

        rmdir($dir);
    }

    /** Rejects paths that traverse a symlinked ancestor outside the project. */
    public function testRejectsSymlinkOrJunctionAncestorEscapingProject(): void
    {
        // Arrange
        $originalCwd = getcwd();
        $project = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-project-' . uniqid('', true);
        $outside = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-outside-' . uniqid('', true);
        mkdir($project);
        mkdir($outside);
        $project = realpath($project);
        $outside = realpath($outside);
        self::assertNotFalse($project);
        self::assertNotFalse($outside);
        $link = $project . DIRECTORY_SEPARATOR . 'link';

        try {
            self::assertTrue(chdir($project));
            $this->createDirectoryLink($outside, $link);
            if (PHP_OS_FAMILY === 'Windows') {
                self::assertTrue($this->isDirectoryJunction($link));
            }

            // Assert
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Safety Violation');
            // Act
            new DeleteTask(['path' => $link . DIRECTORY_SEPARATOR . 'victim.txt'], $this->logger);
        } finally {
            if (is_dir($link) || is_link($link)) {
                $this->removeDirectoryLink($link);
            }

            chdir($originalCwd);
            rmdir($outside);
            rmdir($project);
        }
    }

    /** Reports failure when a recursive copy encounters a symlink. */
    public function testCopyPropagatesNestedSymlinkFailure(): void
    {
        // Arrange
        $originalCwd = getcwd();
        $project = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-copy-project-' . uniqid('', true);
        $outside = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-copy-outside-' . uniqid('', true);
        mkdir($project);
        mkdir($outside);
        $project = realpath($project);
        $outside = realpath($outside);
        self::assertNotFalse($project);
        self::assertNotFalse($outside);

        $source = $project . DIRECTORY_SEPARATOR . 'source';
        $dest = $project . DIRECTORY_SEPARATOR . 'destination';
        try {
            self::assertTrue(chdir($project));
            mkdir($source);
            file_put_contents($source . DIRECTORY_SEPARATOR . 'a-first.txt', 'partial');
            file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt', 'secret');
            $this->createDirectoryLink($outside, $source . DIRECTORY_SEPARATOR . 'linked');
            $task = new CopyTask(['source' => $source, 'dest' => $dest], $this->logger);
            // Act
            $copied = $task->execute(false, true);

            // Assert
            $this->assertFalse($copied);
            $this->assertDirectoryDoesNotExist($dest);
            $this->assertFileDoesNotExist($dest . DIRECTORY_SEPARATOR . 'linked' . DIRECTORY_SEPARATOR . 'secret.txt');
        } finally {
            if (is_dir($source . DIRECTORY_SEPARATOR . 'linked') || is_link($source . DIRECTORY_SEPARATOR . 'linked')) {
                $this->removeDirectoryLink($source . DIRECTORY_SEPARATOR . 'linked');
            }

            if (is_dir($dest)) {
                rmdir($dest);
            }

            if (is_file($source . DIRECTORY_SEPARATOR . 'a-first.txt')) {
                unlink($source . DIRECTORY_SEPARATOR . 'a-first.txt');
            }

            unlink($outside . DIRECTORY_SEPARATOR . 'secret.txt');
            chdir($originalCwd);
            rmdir($source);
            rmdir($outside);
            rmdir($project);
        }
    }

    /** Removes an in-project symlink while preserving its external target. */
    public function testDeleteRemovesSymlinkWithoutDeletingItsTarget(): void
    {
        // Arrange
        $originalCwd = getcwd();
        $project = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-delete-project-' . uniqid('', true);
        $target = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-delete-target-' . uniqid('', true);
        mkdir($project);
        mkdir($target);
        $project = realpath($project);
        $target = realpath($target);
        self::assertNotFalse($project);
        self::assertNotFalse($target);
        $link = $project . DIRECTORY_SEPARATOR . 'link';
        file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'keep me');

        try {
            self::assertTrue(chdir($project));
            $this->createDirectoryLink($target, $link);
            $task = new DeleteTask(['path' => $link], $this->logger);
            // Act
            $deleted = $task->execute(false, true);

            // Assert
            $this->assertTrue($deleted);
            $this->assertDirectoryDoesNotExist($link);
            $this->assertFileExists($target . DIRECTORY_SEPARATOR . 'keep.txt');
            $this->assertSame('keep me', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
        } finally {
            if (is_dir($link) || is_link($link)) {
                $this->removeDirectoryLink($link);
            }

            if (is_file($target . DIRECTORY_SEPARATOR . 'keep.txt')) {
                unlink($target . DIRECTORY_SEPARATOR . 'keep.txt');
            }

            chdir($originalCwd);
            rmdir($target);
            rmdir($project);
        }
    }

    /** Refuses to delete the project root, including normalized aliases. */
    /** Rejects deletion of the active project root and its normalized aliases. */
    public function testDeleteRejectsProjectRoot(): void
    {
        // Arrange
        $root = getcwd();
        // Act
        try {
            new DeleteTask(['path' => $root . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . basename($root)], $this->logger);
            self::fail('Deleting the project root must be rejected.');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            // Assert
            self::assertStringContainsString('project root', $invalidArgumentException->getMessage());
        }
    }


    /** Preserves an existing destination tree when a staged copy fails. */
    public function testFailedCopyPreservesExistingDestination(): void
    {
        // Arrange
        $originalCwd = getcwd();
        $project = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-copy-rollback-' . bin2hex(random_bytes(6));
        $outside = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-copy-target-' . bin2hex(random_bytes(6));
        mkdir($project);
        mkdir($outside);
        $project = realpath($project);
        $outside = realpath($outside);
        self::assertNotFalse($project);
        self::assertNotFalse($outside);
        $source = $project . DIRECTORY_SEPARATOR . 'source';
        $dest = $project . DIRECTORY_SEPARATOR . 'destination';
        mkdir($source);
        mkdir($dest);
        file_put_contents($dest . DIRECTORY_SEPARATOR . 'keep.txt', 'original');
        file_put_contents($source . DIRECTORY_SEPARATOR . 'a-first.txt', 'new');
        $link = $source . DIRECTORY_SEPARATOR . 'z-link';

        try {
            self::assertTrue(chdir($project));
            $this->createDirectoryLink($outside, $link);
            $task = new CopyTask(['source' => $source, 'dest' => $dest], $this->logger);

            // Act
            self::assertFalse($task->execute(false, true));

            // Assert
            self::assertSame('original', file_get_contents($dest . DIRECTORY_SEPARATOR . 'keep.txt'));
            self::assertFileDoesNotExist($dest . DIRECTORY_SEPARATOR . 'a-first.txt');
        } finally {
            if (is_dir($link) || is_link($link)) {
                $this->removeDirectoryLink($link);
            }

            if (is_file($source . DIRECTORY_SEPARATOR . 'a-first.txt')) {
                unlink($source . DIRECTORY_SEPARATOR . 'a-first.txt');
            }

            if (is_file($dest . DIRECTORY_SEPARATOR . 'keep.txt')) {
                unlink($dest . DIRECTORY_SEPARATOR . 'keep.txt');
            }

            chdir($originalCwd);
            rmdir($source);
            rmdir($dest);
            rmdir($outside);
            rmdir($project);
        }
    }


    /** Rejects UNC paths as external paths on Windows. */
    /** Rejects UNC paths as external Windows paths. */
    public function testRejectsUncPathOnWindows(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('UNC path handling is Windows-specific.');
        }

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new DeleteTask(['path' => '\\\\server\\share\\victim.txt'], $this->logger);
    }

    /** Rejects case-only sibling paths on filesystems that distinguish case. */
    /** Rejects case-only sibling paths on filesystems that distinguish case. */
    public function testRejectsCaseVariantSiblingOnCaseSensitiveFilesystem(): void
    {
        // Arrange
        $originalCwd = getcwd();
        $base = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-case-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($base));
        $project = $base . DIRECTORY_SEPARATOR . 'CaseRoot';
        $sibling = $base . DIRECTORY_SEPARATOR . 'caseroot';
        self::assertTrue(mkdir($project));

        // Case-insensitive volumes cannot create both names independently.
        if (!@mkdir($sibling)) {
            rmdir($project);
            rmdir($base);
            self::markTestSkipped('Case-only siblings cannot exist on this filesystem.');
        }

        $victim = $sibling . DIRECTORY_SEPARATOR . 'victim.txt';
        file_put_contents($victim, 'outside');
        try {
            self::assertTrue(chdir($project));

            // Assert
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Safety Violation');

            // Act
            new DeleteTask(['path' => $victim], $this->logger);
        } finally {
            chdir($originalCwd);
            if (is_file($victim)) {
                unlink($victim);
            }

            rmdir($sibling);
            rmdir($project);
            rmdir($base);
        }
    }



    /** Rejects extended-length UNC paths as external Windows paths. */
    /** Rejects extended-length UNC paths as external Windows paths. */
    public function testRejectsExtendedUncPathOnWindows(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Extended UNC path handling is Windows-specific.');
        }

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new DeleteTask(['path' => '\\\\?\\UNC\\server\\share\\victim.txt'], $this->logger);
    }

    /** Rejects Windows drive-relative paths because their base depends on per-drive state. */
    /** Rejects Windows drive-relative paths with ambiguous per-drive bases. */
    public function testRejectsDriveRelativePathOnWindows(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Drive-relative path handling is Windows-specific.');
        }

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Drive-relative paths are not allowed');

        // Act
        new DeleteTask(['path' => 'C:relative\\victim.txt'], $this->logger);
    }




    /** Deletes a hardlink name without deleting or truncating its shared inode. */
    /** Deletes a hardlink name without modifying its shared original file. */
    public function testDeleteHardlinkPreservesOriginalFile(): void
    {
        // Arrange
        $originalCwd = getcwd();
        $project = $this->workspace . DIRECTORY_SEPARATOR . 'phpmake-delete-hardlink-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($project));
        $source = $project . DIRECTORY_SEPARATOR . 'source.txt';
        $alias = $project . DIRECTORY_SEPARATOR . 'alias.txt';
        self::assertSame(9, file_put_contents($source, 'preserved'));
        if (!@link($source, $alias)) {
            @unlink($source);
            @rmdir($project);
            self::markTestSkipped('Hardlink creation is unavailable in this environment.');
        }

        try {
            self::assertTrue(chdir($project));
            $task = new DeleteTask(['path' => 'alias.txt'], $this->logger);

            // Act
            $deleted = $task->execute(false, true);

            // Assert
            self::assertTrue($deleted);
            self::assertFileDoesNotExist($alias);
            self::assertSame('preserved', file_get_contents($source));
        } finally {
            chdir($originalCwd);
            if (is_file($alias)) {
                unlink($alias);
            }

            if (is_file($source)) {
                unlink($source);
            }

            if (is_dir($project)) {
                rmdir($project);
            }
        }
    }

    /** Rejects extended-length drive paths outside the project on Windows. */
    /** Rejects extended-length drive paths outside the project root. */
    public function testRejectsExtendedLengthDrivePathOnWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Extended-length drive paths are Windows-specific.');
        }

        // Arrange
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-extended-path-' . bin2hex(random_bytes(6));
        $extendedPath = '\\\\\\\\?\\\\' . $outside;

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new DeleteTask(['path' => $extendedPath], $this->logger);
    }



    /** Rejects a dangling directory junction used as a path ancestor. */
    /** Rejects a dangling directory link used as a path ancestor. */
    public function testRejectsDanglingDirectoryLinkAncestor(): void
    {
        // Arrange
        $project = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-dangling-link-' . bin2hex(random_bytes(6));
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-dangling-target-' . bin2hex(random_bytes(6));
        $movedOutside = $outside . '-moved';
        $link = $project . DIRECTORY_SEPARATOR . 'linked';
        self::assertTrue(mkdir($project));
        self::assertTrue(mkdir($outside));
        self::assertSame(6, file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt', 'secret'));
        $this->createDirectoryLink($outside, $link);
        self::assertTrue(rename($outside, $movedOutside));

        try {
            // Assert
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Safety Violation');

            // Act
            new DeleteTask(['path' => $link . DIRECTORY_SEPARATOR . 'secret.txt'], $this->logger);
        } finally {
            if (PHP_OS_FAMILY === 'Windows') {
                $this->removeDirectoryLink($link);
            } elseif (is_link($link)) {
                unlink($link);
            }

            if (is_file($movedOutside . DIRECTORY_SEPARATOR . 'secret.txt')) {
                unlink($movedOutside . DIRECTORY_SEPARATOR . 'secret.txt');
            }

            if (is_dir($movedOutside)) {
                rmdir($movedOutside);
            }

            if (is_dir($project)) {
                rmdir($project);
            }
        }
    }

}
