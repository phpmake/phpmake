<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Verifies that debug logging does not persist command-line credentials. */
final class ExecTaskSecurityTest extends TestCase
{
    private string $previousDirectory;

    private string $temporaryDirectory;

    /** Creates an isolated working directory for the process task. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDirectory = getcwd();
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-exec-security-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0777, true));
        self::assertTrue(chdir($this->temporaryDirectory));
    }

    /** Restores the working directory and removes the generated log. */
    protected function tearDown(): void
    {
        chdir($this->previousDirectory);
        FilesystemTree::remove($this->temporaryDirectory);
        parent::tearDown();
    }

    /** Omits secret arguments from both visible debug output and the persistent log. */
    public function testDebugLoggingDoesNotExposeCommandArguments(): void
    {
        // Arrange
        $secret = 'credential-' . bin2hex(random_bytes(12));
        $logger = new Logger(true, false, false, 'build.log');
        $task = new ExecTask([
            'command' => '"' . PHP_BINARY . '"',
            'args' => ['-r', 'exit(0);', $secret],
        ], $logger);

        // Act
        ob_start();
        try {
            $result = $task->execute(true, false);
            $consoleOutput = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        // Assert
        self::assertTrue($result);
        self::assertIsString($consoleOutput);
        self::assertStringContainsString('Executing external process (arguments omitted).', $consoleOutput);
        self::assertStringNotContainsString($secret, $consoleOutput);
        $log = file_get_contents('build.log');
        self::assertIsString($log);
        self::assertStringContainsString('Executing external process (arguments omitted).', $log);
        self::assertStringNotContainsString($secret, $log);
    }
}
