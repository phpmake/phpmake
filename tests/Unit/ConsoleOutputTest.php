<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Cli\ConsoleOutput;
use PHPUnit\Framework\TestCase;

/** Tests the shared CLI output boundary. */
final class ConsoleOutputTest extends TestCase
{
    /** Writes raw output to standard output without adding a line ending. */
    public function testStdoutWritesExactMessageWithoutLineEnding(): void
    {
        // Arrange
        $output = new ConsoleOutput();

        // Act
        ob_start();
        try {
            $output->stdout('raw output');
            $actual = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        // Assert
        self::assertSame('raw output', $actual);
    }

    /** Writes informational output to standard output with one line ending. */
    public function testInfoWritesOneOutputLine(): void
    {
        // Arrange
        $output = new ConsoleOutput();

        // Act
        ob_start();
        try {
            $output->info('Build started');
            $actual = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        // Assert
        self::assertSame('Build started' . PHP_EOL, $actual);
    }

    /** Writes successful task output to standard output with one line ending. */
    public function testSuccessWritesOneOutputLine(): void
    {
        // Arrange
        $output = new ConsoleOutput();
        ob_start();

        try {
            // Act
            $output->success('Task completed');
            $actual = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        // Assert
        self::assertSame('Task completed' . PHP_EOL, $actual);
    }

    /** Writes error output to standard error without contaminating standard output. */
    public function testErrorWritesOneErrorLineToStandardError(): void
    {
        // Arrange
        $sourcePath = dirname(__DIR__, 2) . '/src/Cli/ConsoleOutput.php';
        $script = sprintf(
            'require %s; (new \\PhpMake\\Cli\\ConsoleOutput())->error(%s);',
            var_export($sourcePath, true),
            var_export('Build failed', true)
        );
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the PHP subprocess.');
        }

        fclose($pipes[0]);

        // Act
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Assert
        self::assertSame(0, $exitCode);
        self::assertSame('', $stdout);
        self::assertSame('Build failed' . PHP_EOL, $stderr);
    }

    /** Rejects a non-resource error stream during construction. */
    public function testConstructorRejectsInvalidErrorStream(): void
    {
        // Arrange
        $invalidStream = 'not a stream';

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The error output stream must be a valid resource.');

        // Act
        new ConsoleOutput($invalidStream);
    }
}
