<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Covers filesystem path validation against symlink traversal and volume case semantics. */
final class FilesystemPathResolutionTest extends TestCase
{
    use DirectoryLinkTestHelper;

    private Logger $logger;
    private string $base;
    private string $root;
    private string $outside;
    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger(false, true, true);
        $this->originalCwd = getcwd();
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-path-resolution-' . bin2hex(random_bytes(8));
        $this->root = $this->base . DIRECTORY_SEPARATOR . 'project';
        $this->outside = $this->base . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(mkdir($this->root, 0777, true));
        self::assertTrue(mkdir($this->outside . DIRECTORY_SEPARATOR . 'subdir', 0777, true));
        self::assertTrue(chdir($this->root));

        $link = $this->root . DIRECTORY_SEPARATOR . 'link';
        $this->createDirectoryLink($this->outside . DIRECTORY_SEPARATOR . 'subdir', $link);
        file_put_contents($this->outside . DIRECTORY_SEPARATOR . 'victim.txt', 'preserve');
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeTree($this->base);
        parent::tearDown();
    }

    /** Rejects symlink/parent traversal for delete, mkdir, copy, and archive operations. */
    public function testFilesystemTasksRejectSymlinkThenParentTraversal(): void
    {
        // Arrange
        $escape = 'link' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'victim.txt';
        $insideFile = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        file_put_contents($insideFile, 'source');

        // Act / Assert
        $this->assertRejected(fn() => new DeleteTask(['path' => $escape], $this->logger));
        $this->assertRejected(fn() => new CreateDirectoryTask(['name' => $escape], $this->logger));
        $this->assertRejected(fn() => new CopyTask([
            'source' => $escape,
            'dest' => 'copy.txt',
        ], $this->logger));
        $this->assertRejected(fn() => new CopyTask([
            'source' => 'source.txt',
            'dest' => 'link' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'written.txt',
        ], $this->logger));
        $this->assertRejected(fn() => new ZipTask([
            'source' => $escape,
            'output' => 'archive.zip',
        ], $this->logger));
        $this->assertRejected(fn() => new ZipTask([
            'source' => 'source.txt',
            'output' => 'link' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'archive.zip',
        ], $this->logger));

        // Verify
        self::assertSame('preserve', file_get_contents($this->outside . DIRECTORY_SEPARATOR . 'victim.txt'));
        self::assertFileDoesNotExist($this->outside . DIRECTORY_SEPARATOR . 'written.txt');
        self::assertFileDoesNotExist($this->outside . DIRECTORY_SEPARATOR . 'archive.zip');
    }

    /** Uses canonical filesystem spelling instead of assuming macOS is case-insensitive. */
    public function testAbsolutePathCaseHandlingFollowsMountedVolume(): void
    {
        // Arrange
        $alternateRoot = preg_replace_callback(
            '/[a-zA-Z]/',
            static fn(array $match): string => ctype_lower($match[0]) ? strtoupper($match[0]) : strtolower($match[0]),
            $this->root,
            1
        );
        $candidate = $alternateRoot . DIRECTORY_SEPARATOR . 'case-check';

        // Act / Assert
        if (realpath($alternateRoot) !== false) {
            $task = new CreateDirectoryTask(['name' => $candidate], $this->logger);
            self::assertTrue($task->execute(false, true));
            self::assertDirectoryExists($this->root . DIRECTORY_SEPARATOR . 'case-check');
        } else {
            $this->assertRejected(fn() => new CreateDirectoryTask(['name' => $candidate], $this->logger));
        }
    }

    /** Revalidates filesystem paths after an ancestor is replaced by an external link. */
    public function testFilesystemTasksRevalidatePathsImmediatelyBeforeExecution(): void
    {
        // Arrange
        $safeDirectory = $this->root . DIRECTORY_SEPARATOR . 'replaceable';
        $savedDirectory = $this->root . DIRECTORY_SEPARATOR . 'replaceable-original';
        $outsideVictim = $this->outside . DIRECTORY_SEPARATOR . 'subdir' . DIRECTORY_SEPARATOR . 'victim.txt';
        self::assertTrue(mkdir($safeDirectory));
        self::assertSame(6, file_put_contents($safeDirectory . DIRECTORY_SEPARATOR . 'victim.txt', 'inside'));
        self::assertSame(7, file_put_contents($outsideVictim, 'outside'));
        self::assertSame(6, file_put_contents($this->root . DIRECTORY_SEPARATOR . 'source.txt', 'source'));

        $tasks = [
            new DeleteTask(['path' => 'replaceable' . DIRECTORY_SEPARATOR . 'victim.txt'], $this->logger),
            new CreateDirectoryTask(['name' => 'replaceable' . DIRECTORY_SEPARATOR . 'created'], $this->logger),
            new CopyTask([
                'source' => 'source.txt',
                'dest' => 'replaceable' . DIRECTORY_SEPARATOR . 'copied.txt',
            ], $this->logger),
            new ZipTask([
                'source' => 'source.txt',
                'output' => 'replaceable' . DIRECTORY_SEPARATOR . 'archive.zip',
            ], $this->logger),
        ];

        self::assertTrue(rename($safeDirectory, $savedDirectory));
        $this->createDirectoryLink($this->outside . DIRECTORY_SEPARATOR . 'subdir', $safeDirectory);

        try {
            // Act
            $results = array_map(static fn($task): bool => $task->execute(false, true), $tasks);

            // Assert
            self::assertSame([false, false, false, false], $results);
            self::assertSame('outside', file_get_contents($outsideVictim));
            self::assertDirectoryDoesNotExist($this->outside . DIRECTORY_SEPARATOR . 'subdir' . DIRECTORY_SEPARATOR . 'created');
            self::assertFileDoesNotExist($this->outside . DIRECTORY_SEPARATOR . 'subdir' . DIRECTORY_SEPARATOR . 'copied.txt');
            self::assertFileDoesNotExist($this->outside . DIRECTORY_SEPARATOR . 'subdir' . DIRECTORY_SEPARATOR . 'archive.zip');
            self::assertSame('inside', file_get_contents($savedDirectory . DIRECTORY_SEPARATOR . 'victim.txt'));
        } finally {
            if (is_link($safeDirectory) || $this->isDirectoryJunction($safeDirectory)) {
                $this->removeDirectoryLink($safeDirectory);
            }
            if (is_dir($savedDirectory)) {
                rename($savedDirectory, $safeDirectory);
            }
            if (is_file($outsideVictim)) {
                unlink($outsideVictim);
            }
            if (is_file($this->root . DIRECTORY_SEPARATOR . 'source.txt')) {
                unlink($this->root . DIRECTORY_SEPARATOR . 'source.txt');
            }
        }
    }

    /** Asserts that construction rejects a path outside the project root. */
    private function assertRejected(callable $factory): void
    {
        try {
            $factory();
            self::fail('Expected filesystem path validation to reject the traversal path.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('escapes project directory', $exception->getMessage());
        }
    }

    /** Removes test data without traversing links or junctions. */
    private function removeTree(string $path): void
    {
        if (is_link($path) || $this->isDirectoryJunction($path)) {
            $this->removeDirectoryLink($path);
            return;
        }
        if (is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->removeTree($path . DIRECTORY_SEPARATOR . $item);
                }
            }
        }
        @rmdir($path);
    }
}
