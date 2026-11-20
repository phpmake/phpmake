<?php

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Task\BaseTask;
use PhpMake\Tests\Support\DirectoryLinkTestHelper;
use PhpMake\Utilities\Logger;

/** Tests ZIP task structure, archive contents, and symlink handling. */
final class ZipTaskTest extends TestCase
{
    use DirectoryLinkTestHelper;

    /**
     * This method is called before the first test method in the test class is executed.
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public static function setUpBeforeClass(): void {}

    /**
     * This method is called after the last test method in the test class has been executed.
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public static function tearDownAfterClass(): void
    {
        gc_collect_cycles();
    }

    /**
     * This method is called BEFORE each test method.
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    protected function setUp(): void
    {
        parent::setUp();
        // Methods initialization codes.
    }

    /**
     * This method is called AFTER each test method.
     *
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    protected function tearDown(): void
    {
        // Methods finalization codes.
        parent::tearDown();
    }

    /** Ensures an archive created inside its source directory does not include itself. */
    public function testArchiveDoesNotIncludeItselfWhenOutputIsInsideSource(): void
    {
        // Arrange
        if (!class_exists(\ZipArchive::class)) {
            // Assert
            $this->markTestSkipped('ext-zip is not available.');
        }

        $root = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-zip-self-' . uniqid('', true);
        mkdir($root);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'input.txt', 'content');
        $output = $root . DIRECTORY_SEPARATOR . 'archive.zip';
        try {
            $task = new ZipTask(['source' => $root, 'output' => $output], new Logger(false, true, true));
            // Act
            $this->assertTrue($task->execute(false, true));
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($output));
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
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not available.');
        }

        if (!in_array(PHP_OS_FAMILY, ['Windows', 'Darwin'], true)) {
            self::assertTrue(true, 'Case-variant filesystem test is not applicable on this platform.');
            return;
        }

        $root = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-zip-case-' . bin2hex(random_bytes(6));
        mkdir($root);
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
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not available.');
        }

        // Use the system temporary volume rather than the repository volume.
        // Some Windows workspaces are mounted on filesystems that cannot host
        // NTFS junctions, while the system temporary volume supports them.
        $originalCwd = getcwd();
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-zip-transaction-' . bin2hex(random_bytes(6));
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
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not available.');
        }

        $suffix = bin2hex(random_bytes(8));
        $source = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-zip-oversized-' . $suffix;
        $output = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-zip-preserve-' . $suffix . '.zip';
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

}
