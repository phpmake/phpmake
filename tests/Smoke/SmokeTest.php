<?php

declare(strict_types=1);

namespace PhpMake\Tests\Smoke {
    use PhpMake\Cli\CliParser;
    use PhpMake\Task\Types\CopyTask;
    use PhpMake\Tests\Support\TemporaryDirectoryTestHelper;
    use PhpMake\Utilities\Logger;
    use PHPUnit\Framework\TestCase;

    /** Smoke tests for autoloading, CLI parsing, and a real filesystem task. */
    final class SmokeTest extends TestCase
    {
        use TemporaryDirectoryTestHelper;

        private string $workspace;

        private string $originalWorkingDirectory;

        /** Creates an isolated project root for smoke-test filesystem operations. */
        protected function setUp(): void
        {
            parent::setUp();
            $this->originalWorkingDirectory = (string) getcwd();
            $this->workspace = $this->createTemporaryDirectory('phpmake-smoke-');
            self::assertTrue(chdir($this->workspace));
        }

        /** Restores the working directory and removes all smoke-test artifacts. */
        protected function tearDown(): void
        {
            chdir($this->originalWorkingDirectory);
            $this->removeTemporaryDirectory($this->workspace);
            parent::tearDown();
        }

        /** Confirms Composer can autoload the public application classes. */
        public function testComposerCanLoadLibraryClasses(): void
        {
            // Arrange
            $classes = [
                CliParser::class,
                CopyTask::class,
                Logger::class,
            ];

            // Act
            $loaded = array_map('class_exists', $classes);

            // Assert
            self::assertNotContains(false, $loaded);
        }

        /** Verifies CLI flags and target arguments are parsed. */
        public function testCliParserParsesFlagsAndTarget(): void
        {
            // Arrange
            $parser = new CliParser();

            // Act
            $result = $parser->parse(['bin/phpmake.php', '--debug', '--help', 'build']);

            // Assert
            self::assertTrue($result['debug']);
            self::assertTrue($result['help']);
            self::assertSame('build', $result['target']);
        }

        /** Verifies a real copy task copies file contents successfully. */
        public function testCopyTaskCopiesFileContents(): void
        {
            // Arrange
            $baseDir = $this->workspace . DIRECTORY_SEPARATOR . 'copy-fixture';
            self::assertTrue(mkdir($baseDir));
            $sourceFile = $baseDir . DIRECTORY_SEPARATOR . 'source.txt';
            $destFile = $baseDir . DIRECTORY_SEPARATOR . 'destination.txt';
            file_put_contents($sourceFile, 'Test copy');

            try {
                $copyTask = new CopyTask(
                    ['source' => $sourceFile, 'dest' => $destFile],
                    new Logger(true, true, true)
                );

                // Act
                $result = $copyTask->execute(false, true);

                // Assert
                self::assertTrue($result);
                self::assertFileExists($destFile);
                self::assertSame('Test copy', file_get_contents($destFile));
            } finally {
                if (is_file($sourceFile)) {
                    unlink($sourceFile);
                }

                if (is_file($destFile)) {
                    unlink($destFile);
                }

                if (is_dir($baseDir)) {
                    rmdir($baseDir);
                }
            }
        }
    }
}
