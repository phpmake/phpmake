<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PhpMake\Tests\Support\TemporaryDirectoryTestHelper;
use PhpMake\Utilities\Logger;

/** Tests ZIP task structure, archive contents, and symlink handling. */
final class ZipTaskTest extends TestCase
{
    use DirectoryLinkTestHelper;
    use TemporaryDirectoryTestHelper;

    private string $root;

    private string $originalCwd;

    /** Creates an isolated workspace for archive tests. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCwd = (string) getcwd();
        $this->root = $this->createTemporaryDirectory('phpmake-zip-tests-');
        self::assertTrue(chdir($this->root));
    }

    /** Restores the working directory and removes archive test fixtures. */
    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeTemporaryDirectory($this->root);
        parent::tearDown();
    }





    /** Ensures an archive created inside its source directory does not include itself. */
    public function testArchiveDoesNotIncludeItselfWhenOutputIsInsideSource(): void
    {
        // Arrange
        self::assertTrue(class_exists(\ZipArchive::class), 'ext-zip is a required runtime dependency.');

        $root = $this->root . DIRECTORY_SEPARATOR . 'self';
        self::assertTrue(mkdir($root));
        file_put_contents($root . DIRECTORY_SEPARATOR . 'input.txt', 'content');
        $output = $root . DIRECTORY_SEPARATOR . 'archive.zip';
        try {
            $task = new ZipTask(['source' => $root, 'output' => $output], new Logger(false, true, true));
            // Act
            $archived = $task->execute(false, true);
            $zip = new \ZipArchive();
            $opened = $zip->open($output);

            // Assert
            $this->assertTrue($archived);
            $this->assertTrue($opened);
            $this->assertSame(1, $zip->numFiles);
            $this->assertSame('input.txt', $zip->getNameIndex(0));
            $zip->close();
        } finally {
            if (file_exists($output)) {
                unlink($output);
            }

            if (file_exists($root . DIRECTORY_SEPARATOR . 'input.txt')) {
                unlink($root . DIRECTORY_SEPARATOR . 'input.txt');
            }

            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    /** Excludes the output archive only when the configured path aliases the same filesystem entry. */
    public function testArchiveExcludesOutputWhenPathCaseAliasesIt(): void
    {
        // Arrange
        self::assertTrue(class_exists(\ZipArchive::class), 'ext-zip is a required runtime dependency.');

        $root = $this->root . DIRECTORY_SEPARATOR . 'case';
        self::assertTrue(mkdir($root));
        file_put_contents($root . DIRECTORY_SEPARATOR . 'input.txt', 'content');
        $actualOutput = $root . DIRECTORY_SEPARATOR . 'archive.zip';
        $configuredOutput = $root . DIRECTORY_SEPARATOR . 'ARCHIVE.ZIP';
        file_put_contents($actualOutput, '');
        $actualStat = @stat($actualOutput);
        $configuredStat = @stat($configuredOutput);
        $pathsAlias = is_array($actualStat)
            && is_array($configuredStat)
            && $actualStat['dev'] === $configuredStat['dev']
            && $actualStat['ino'] === $configuredStat['ino'];

        try {
            $task = new ZipTask(['source' => $root, 'output' => $configuredOutput], new Logger(false, true, true));

            // Act
            self::assertTrue($task->execute(false, true));

            // Assert
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($configuredOutput));
            self::assertSame($pathsAlias ? 1 : 2, $zip->numFiles);
            self::assertNotFalse($zip->locateName('input.txt'));
            self::assertFalse($zip->locateName('ARCHIVE.ZIP'));
            self::assertSame($pathsAlias, $zip->locateName('archive.zip') === false);
            $zip->close();
        } finally {
            if (file_exists($configuredOutput)) {
                unlink($configuredOutput);
            }

            if (file_exists($actualOutput)) {
                unlink($actualOutput);
            }

            if (file_exists($root . DIRECTORY_SEPARATOR . 'input.txt')) {
                unlink($root . DIRECTORY_SEPARATOR . 'input.txt');
            }

            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    /** Preserves a previously valid archive when source traversal fails. */
    public function testArchiveFailurePreservesExistingOutput(): void
    {
        // Arrange
        self::assertTrue(class_exists(\ZipArchive::class), 'ext-zip is a required runtime dependency.');

        // Use the system temporary volume rather than the repository volume.
        // Some Windows workspaces are mounted on filesystems that cannot host
        // NTFS junctions, while the system temporary volume supports them.
        $originalCwd = getcwd();
        $base = $this->root . DIRECTORY_SEPARATOR . 'transaction';
        $root = $base . DIRECTORY_SEPARATOR . 'project';
        $outside = $base . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(mkdir($base));
        self::assertTrue(mkdir($root));
        self::assertTrue(mkdir($outside));
        $output = $root . DIRECTORY_SEPARATOR . 'archive.zip';
        $link = $root . DIRECTORY_SEPARATOR . 'linked-directory';

        try {
            $original = new \ZipArchive();
            self::assertTrue($original->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            self::assertTrue($original->addFromString('preserve.txt', 'original archive'));
            self::assertTrue($original->close());
            $this->createDirectoryLink($outside, $link);
            self::assertTrue(chdir($base));

            // Act
            $task = new ZipTask(['source' => $root, 'output' => $output], new Logger(false, true, true));
            self::assertFalse($task->execute(false, true));

            // Assert
            $preserved = new \ZipArchive();
            self::assertTrue($preserved->open($output));
            self::assertSame('original archive', $preserved->getFromName('preserve.txt'));
            self::assertTrue($preserved->close());
            self::assertSame([], glob($root . DIRECTORY_SEPARATOR . '.phpmake-archive-*') ?: []);
            self::assertSame([], glob($root . DIRECTORY_SEPARATOR . '.phpmake-archive-input-*') ?: []);
        } finally {
            if (is_string($originalCwd)) {
                chdir($originalCwd);
            }

            if (is_link($link) || $this->isDirectoryJunction($link)) {
                $this->removeDirectoryLink($link);
            }

            if (is_file($output)) {
                unlink($output);
            }

            if (is_dir($root)) {
                rmdir($root);
            }

            if (is_dir($outside)) {
                rmdir($outside);
            }

            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }

    /** Rejects oversized archive inputs without replacing an existing output archive. */
    public function testArchiveInputLimitPreservesExistingOutput(): void
    {
        // Arrange
        self::assertTrue(class_exists(\ZipArchive::class), 'ext-zip is a required runtime dependency.');

        $suffix = bin2hex(random_bytes(8));
        $source = $this->root . DIRECTORY_SEPARATOR . '.phpmake-zip-oversized-' . $suffix;
        $output = $this->root . DIRECTORY_SEPARATOR . '.phpmake-zip-preserve-' . $suffix . '.zip';
        $handle = fopen($source, 'wb');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, 536870913));
        fclose($handle);

        $original = new \ZipArchive();
        self::assertTrue($original->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($original->addFromString('preserve.txt', 'original archive'));
        self::assertTrue($original->close());

        try {
            // Act
            $task = new ZipTask(['source' => $source, 'output' => $output], new Logger(false, true, true));
            self::assertFalse($task->execute(false, true));

            // Assert
            $preserved = new \ZipArchive();
            self::assertTrue($preserved->open($output));
            self::assertSame('original archive', $preserved->getFromName('preserve.txt'));
            self::assertTrue($preserved->close());
        } finally {
            if (is_file($source)) {
                unlink($source);
            }

            if (is_file($output)) {
                unlink($output);
            }
        }
    }



    /** Archives immutable input snapshots and removes them after publication. */
    public function testArchiveSnapshotsInputsAndCleansTemporaryFiles(): void
    {
        // Arrange
        self::assertTrue(class_exists(\ZipArchive::class), 'ext-zip is a required runtime dependency.');

        $root = $this->root . DIRECTORY_SEPARATOR . 'snapshot';
        self::assertTrue(mkdir($root));
        $source = $root . DIRECTORY_SEPARATOR . 'input.txt';
        $output = $root . DIRECTORY_SEPARATOR . 'archive.zip';
        self::assertSame(14, file_put_contents($source, 'stable content'));

        try {
            // Act
            $task = new ZipTask(['source' => dirname($source), 'output' => $output], new Logger(false, true, true));
            self::assertTrue($task->execute(false, true));

            // Assert
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($output));
            self::assertSame(1, $zip->numFiles);
            self::assertSame('stable content', $zip->getFromName('input.txt'));
            self::assertTrue($zip->close());
            self::assertSame([], glob($root . DIRECTORY_SEPARATOR . '.phpmake-archive-input-*') ?: []);
        } finally {
            foreach (glob($root . DIRECTORY_SEPARATOR . '.phpmake-archive-input-*') ?: [] as $snapshot) {
                if (is_file($snapshot) && !unlink($snapshot)) {
                    throw new \\RuntimeException(sprintf('Unable to remove ZIP input snapshot "%s".', $snapshot));
                }
            }

            foreach ([$output, $source] as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new \\RuntimeException(sprintf('Unable to remove ZIP test fixture "%s".', $path));
                }
            }

            if (is_dir($root) && !rmdir($root)) {
                throw new \\RuntimeException(sprintf('Unable to remove ZIP test directory "%s".', $root));
            }
        }
    }

}
