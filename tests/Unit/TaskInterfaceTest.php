<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\TaskInterface;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests the public contract implemented by concrete task classes. */
final class TaskInterfaceTest extends TestCase
{
    /** Executes an echo task through the task interface and exposes its type. */
    public function testEchoTaskImplementsAndExecutesTaskInterface(): void
    {
        // Arrange
        $task = new EchoTask(
            ['message' => 'Interface contract'],
            new Logger(false, true, true)
        );

        // Act
        $type = $task->getType();
        $result = $task->execute(false, true);

        // Assert
        self::assertInstanceOf(TaskInterface::class, $task);
        self::assertSame('echo', $type);
        self::assertTrue($result);
    }
}
