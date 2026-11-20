<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Cli\ConsoleOutput;
use PHPUnit\Framework\TestCase;

/** Tests the shared CLI output boundary. */
final class ConsoleOutputTest extends TestCase
{
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
}
