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
    /** Built-in tasks expose the interface methods and execute through the contract. */
    public function testEchoTaskImplementsTaskInterface(): void
    {
        $task = new EchoTask(
            ['message' => 'Interface contract'],
            new Logger(false, true, true)
        );

        self::assertInstanceOf(TaskInterface::class, $task);
        self::assertSame('echo', $task->getType());
        self::assertTrue($task->execute(false, true));
    }
}
