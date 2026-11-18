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
        $originalCwd = getcwd();
        $project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-project-' . uniqid('', true);
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-outside-' . uniqid('', true);
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
        $project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-copy-project-' . uniqid('', true);
        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-copy-outside-' . uniqid('', true);
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
            $this->assertFalse($task->execute(false, true));
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
        $project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-delete-project-' . uniqid('', true);
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-delete-target-' . uniqid('', true);
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
            chdir($originalCwd);
            rmdir($target);
            rmdir($project);
        }
    }

    /** Refuses to delete the project root, including normalized aliases. */
    public function testDeleteRejectsProjectRoot(): void
    {
        // Arrange
        $root = getcwd();
        // Act
        try {
            new DeleteTask(['path' => $root . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . basename($root)], $this->logger);
            self::fail('Deleting the project root must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            // Assert
            self::assertStringContainsString('project root', $exception->getMessage());
        }
    }

}
