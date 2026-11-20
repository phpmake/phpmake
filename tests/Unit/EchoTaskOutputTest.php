<?php

declare(strict_types=1);

namespace PhpMake\\Tests\\Unit;

use PhpMake\\Task\\Types\\EchoTask;
use PhpMake\\Utilities\\Logger;
use PHPUnit\\Framework\\TestCase;

/** Tests the echo task's user-visible output contract. */
final class EchoTaskOutputTest extends TestCase
{
    /** Writes the configured message when output is not silent. */
    public function testExecuteWritesMessageToConsole(): void
    {
        // Arrange
        $task = new EchoTask(
            ['message' => 'Visible task output'],
            new Logger(false, false, true)
        );

        // Act
        ob_start();
        try {
            $result = $task->execute(false, false);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        // Assert
        self::assertTrue($result);
        self::assertSame('Visible task output' . PHP_EOL, $output);
    }
}
