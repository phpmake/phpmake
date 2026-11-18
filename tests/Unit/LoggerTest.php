<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

    /** Tests console output, file logging, and task logging behavior. */
final class LoggerTest extends TestCase
{
    private Logger $logger;

    /**
     * This method is called before the first test method in the test class is executed.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {}

    /**
     * This method is called after the last test method in the test class has been executed.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        gc_collect_cycles();
    }

    /**
     * This method is called BEFORE each test method.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new Logger(true, true, true);
    }

    /**
     * This method is called AFTER each test method.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Methods finalization codes.
        parent::tearDown();
    }

    /**
     * Create a unique directory under the project CWD for safe filesystem tests.
     */
    private function makeTestDir(string $prefix): string
    {
        $dir = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-test-' . $prefix . '-' . uniqid('', true);
        mkdir($dir, 0o777, true);

        return $dir;
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testBasicLogging(): void
    {
        // Arrange
        $logger = new Logger(false, false, true);
        // Action
        ob_start();
        $logger->info('Test info');
        $output = ob_get_clean();
        // Assert
        $this->assertEquals('Test info' . PHP_EOL, $output);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testLoggerInfoOutput(): void
    {
        // Arrange
        // Create a Logger instance that prints messages (not silent) but does not write to file.
        $logger = new Logger(false, false, true);
        // Action
        ob_start();
        $logger->info('Info Message');
        $output = ob_get_clean();
        // Assert
        $this->assertStringContainsString('Info Message', $output);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testDeleteTask(): void
    {
        // Arrange — paths must stay inside the project directory.
        $baseDir = $this->makeTestDir('del');
        $tempFile = $baseDir . DIRECTORY_SEPARATOR . 'file.txt';
        file_put_contents($tempFile, 'to be deleted');
        $deleteTask = new DeleteTask(['path' => $tempFile], $this->logger);
        // Action
        $result = $deleteTask->execute(false, true);
        // Assert
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($tempFile);

        // Arrange — directory deletion
        $tempDir = $this->makeTestDir('delDir');
        $fileInDir = $tempDir . DIRECTORY_SEPARATOR . 'file.txt';
        file_put_contents($fileInDir, 'content');
        $deleteTaskDir = new DeleteTask(['path' => $tempDir], $this->logger);
        // Action
        $resultDir = $deleteTaskDir->execute(false, true);
        // Assert
        $this->assertTrue($resultDir);
        $this->assertDirectoryDoesNotExist($tempDir);

        // Cleanup base dir from first case
        @rmdir($baseDir);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testEchoTask(): void
    {
        // Arrange
        // Create a Logger that outputs messages (not silent).
        $logger = new Logger(false, false, true);
        ob_start();
        $echoTask = new EchoTask(['message' => 'Echo test'], $logger);
        // Action
        $result = $echoTask->execute(false, false);
        // Assert
        $this->assertTrue($result);
        $output = ob_get_clean();
        $this->assertStringContainsString('Echo test', $output);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testExecTask(): void
    {
        // Arrange
        // Execute a simple echo command.
        $task = new ExecTask(['command' => PHP_BINARY, 'args' => ['-r', 'echo "Hello";']], $this->logger);
        // Action
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testZipTask(): void
    {
        // Arrange — paths must stay inside the project directory.
        // Keep source dir and output zip as siblings so the archive is not written into the source tree.
        $baseDir = $this->makeTestDir('zip');
        $sourceDir = $baseDir . DIRECTORY_SEPARATOR . 'src';
        mkdir($sourceDir);
        $fileInDir = $sourceDir . DIRECTORY_SEPARATOR . 'file.txt';
        file_put_contents($fileInDir, 'zip content');
        $zipFile = $baseDir . DIRECTORY_SEPARATOR . 'out.zip';
        $task = new ZipTask(['source' => $sourceDir, 'output' => $zipFile], $this->logger);
        // Action
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
        $this->assertFileExists($zipFile);
        // Cleanup.
        unlink($zipFile);
        unlink($fileInDir);
        rmdir($sourceDir);
        rmdir($baseDir);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testDebugEnabled(): void
    {
        // Arrange
        $logger = new Logger(true, false, true);
        // Action
        ob_start();
        $logger->debug('Test debug');
        $output = ob_get_clean();
        // Assert
        $this->assertEquals('DEBUG: Test debug' . PHP_EOL, $output);
    }

    /** File rotation failures must not interrupt normal logger calls. */
    public function testFileLoggingFailureDoesNotThrow(): void
    {
        // Arrange
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-log-' . bin2hex(random_bytes(8));
        mkdir($base);
        $logFile = $base . DIRECTORY_SEPARATOR . 'build.log';
        file_put_contents($logFile, str_repeat('x', 10 * 1024 * 1024 + 1));
        mkdir($logFile . '.5');

        $logger = new Logger(false, true, false, $logFile);
        try {
        // Act
            $logger->info('Build continues after log rotation failure');
        // Assert
            self::assertDirectoryExists($logFile . '.5');
        } finally {
            unlink($logFile);
            rmdir($logFile . '.5');
            @unlink($logFile . '.lock');
            rmdir($base);
        }
    }



    /** Logger state accessors reflect debug and silent configuration. */
    public function testLoggerStateAccessors(): void
    {
        // Arrange
        $normal = new Logger(true, false, true);
        $silent = new Logger(true, true, true);
        $nonDebug = new Logger(false, false, true);

        // Assert
        // Act
        self::assertTrue($normal->isDebugEnabled());
        self::assertFalse($normal->isSilent());
        self::assertFalse($silent->isDebugEnabled());
        self::assertTrue($silent->isSilent());
        self::assertFalse($nonDebug->isDebugEnabled());
        self::assertFalse($nonDebug->isSilent());
    }

    /** Error messages are persisted with the ERROR level when file logging is enabled. */
    public function testLoggerErrorWritesErrorEntryToFile(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-error-log-' . bin2hex(random_bytes(8));
        // Assert
        self::assertTrue(mkdir($directory));
        $logFile = $directory . DIRECTORY_SEPARATOR . 'build.log';
        $logger = new Logger(false, true, false, $logFile);

        try {
        // Act
            $logger->error('Expected logger error');

            self::assertFileExists($logFile);
            self::assertStringContainsString('ERROR: Expected logger error', (string) file_get_contents($logFile));
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }
            if (is_file($logFile . '.lock')) {
                unlink($logFile . '.lock');
            }
            rmdir($directory);
        }
    }

}
