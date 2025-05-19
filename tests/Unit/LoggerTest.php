<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

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
        // Arrange
        // Test file deletion.
        $tempFile = tempnam(sys_get_temp_dir(), 'del_');
        file_put_contents($tempFile, 'to be deleted');
        $deleteTask = new DeleteTask(['path' => $tempFile], $this->logger);
        // Action
        $result = $deleteTask->execute(false, true);
        // Assert
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($tempFile);
        // Arrange
        // Test directory deletion.
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('delDir_');
        mkdir($tempDir);
        $fileInDir = $tempDir . DIRECTORY_SEPARATOR . 'file.txt';
        file_put_contents($fileInDir, 'content');
        $deleteTaskDir = new DeleteTask(['path' => $tempDir], $this->logger);
        // Action
        $resultDir = $deleteTaskDir->execute(false, true);
        // Assert
        $this->assertTrue($resultDir);
        $this->assertDirectoryDoesNotExist($tempDir);
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
        $task = new ExecTask(['command' => 'echo Hello'], $this->logger);
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
        // Arrange
        // Create a temporary directory with a file.
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zipDir_');
        mkdir($tempDir);
        $fileInDir = $tempDir . DIRECTORY_SEPARATOR . 'file.txt';
        file_put_contents($fileInDir, 'zip content');
        // Specify a temporary zip file output.
        $zipFile = tempnam(sys_get_temp_dir(), 'zip_') . '.zip';
        $task = new ZipTask(['source' => $tempDir, 'output' => $zipFile], $this->logger);
        // Action
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
        $this->assertFileExists($zipFile);
        // Cleanup.
        unlink($zipFile);
        unlink($fileInDir);
        rmdir($tempDir);
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
}
