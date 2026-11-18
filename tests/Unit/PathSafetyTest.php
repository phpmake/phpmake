<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PHPUnit\Framework\TestCase;

    /** Tests filesystem boundary enforcement and symbolic-link safety. */
final class PathSafetyTest extends TestCase
{
    use DirectoryLinkTestHelper;

    private Logger $logger;

    /** Initializes a silent logger for filesystem safety tests. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger(false, true, true);
    }

    /**
     * @test
     *
     * @small
     */
    public function testDeleteRejectsPathOutsideProject(): void
    {
        // Arrange
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new DeleteTask(['path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'outside.txt'], $this->logger);
    }

    /**
     * @test
     *
     * @small
     */
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

    /**
     * @test
     *
     * @small
     */
    public function testCreateDirectoryRejectsParentTraversal(): void
    {
        // Arrange
        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Safety Violation');

        // Act
        new CreateDirectoryTask(['name' => '..' . DIRECTORY_SEPARATOR . 'outside-dir'], $this->logger);
    }

    /**
     * @test
     *
     * @small
     */
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

    /**
     * @test
     *
     * @small
     */
    public function testCreateDirectoryIsIdempotent(): void
    {
        // Arrange
        $dir = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-test-idempotent-' . uniqid('', true);

        $task = new CreateDirectoryTask(['name' => $dir], $this->logger);
        // Act
        // Assert
        $this->assertTrue($task->execute(false, true));
        $this->assertTrue(is_dir($dir));

        // Second run must succeed when directory already exists.
        $task2 = new CreateDirectoryTask(['name' => $dir], $this->logger);
        $this->assertTrue($task2->execute(false, true));

        rmdir($dir);
    }

    /** Rejects paths that traverse a symlinked ancestor outside the project. */
    public function testRejectsSymlinkedAncestorEscapingProject(): void
    {
        // Arrange
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-outside-' . uniqid('', true);
        $link = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-link-' . uniqid('', true);
        mkdir($outside);
        $this->createDirectoryLink($outside, $link);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Safety Violation');
        // Act
            new DeleteTask(['path' => $link . DIRECTORY_SEPARATOR . 'victim.txt'], $this->logger);
        } finally {
            $this->removeDirectoryLink($link);
            rmdir($outside);
        }
    }

    /** Reports failure when a recursive copy encounters a symlink. */
    public function testCopyPropagatesNestedSymlinkFailure(): void
    {
        // Arrange
        $source = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-copy-src-' . uniqid('', true);
        $dest = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-copy-dest-' . uniqid('', true);
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-copy-outside-' . uniqid('', true);
        mkdir($source);
        mkdir($outside);
        file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret.txt', 'secret');
        $this->createDirectoryLink($outside, $source . DIRECTORY_SEPARATOR . 'linked');
        try {
            $task = new CopyTask(['source' => $source, 'dest' => $dest], $this->logger);
        // Act
            $this->assertFalse($task->execute(false, true));
            $this->assertFileDoesNotExist($dest . DIRECTORY_SEPARATOR . 'linked' . DIRECTORY_SEPARATOR . 'secret.txt');
        } finally {
            $this->removeDirectoryLink($source . DIRECTORY_SEPARATOR . 'linked');
            rmdir($source);
            if (is_dir($dest)) {
                rmdir($dest);
            }
            unlink($outside . DIRECTORY_SEPARATOR . 'secret.txt');
            rmdir($outside);
        }
    }

    /** Removes an in-project symlink while preserving its external target. */
    public function testDeleteRemovesSymlinkWithoutDeletingItsTarget(): void
    {
        // Arrange
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-delete-target-' . uniqid('', true);
        $link = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-delete-link-' . uniqid('', true);
        mkdir($target);
        file_put_contents($target . DIRECTORY_SEPARATOR . 'keep.txt', 'keep me');
        $this->createDirectoryLink($target, $link);

        try {
            $task = new DeleteTask(['path' => $link], $this->logger);
        // Act
            $this->assertTrue($task->execute(false, true));
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
            if (is_dir($target)) {
                rmdir($target);
            }
        }
    }
    /** Removes a directory symlink or Windows junction without traversing its target. */
    private function removeDirectoryLink(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows' && is_dir($path)) {
            rmdir($path);
            return;
        }
        unlink($path);
    }

}
