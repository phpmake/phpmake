<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Process\WindowsConsoleSignalHandler;
use PHPUnit\Framework\TestCase;

/** Tests cooperative Windows console cancellation event handling. */
final class WindowsConsoleSignalHandlerTest extends TestCase
{
    /** Converts Ctrl+C into a cancellation request and consumes the event. */
    public function testCtrlCRequestsCancellation(): void
    {
        // Arrange
        $received = [];
        $handler = new WindowsConsoleSignalHandler(static function (string $event) use (&$received): void {
            $received[] = $event;
        });

        // Act
        $handled = $handler->handleEvent(0);

        // Assert
        self::assertTrue($handled);
        self::assertSame(['CTRL_C_EVENT'], $received);
    }

    /** Converts Ctrl+Break into a cancellation request and consumes the event. */
    public function testCtrlBreakRequestsCancellation(): void
    {
        // Arrange
        $received = [];
        $handler = new WindowsConsoleSignalHandler(static function (string $event) use (&$received): void {
            $received[] = $event;
        });

        // Act
        $handled = $handler->handleEvent(1);

        // Assert
        self::assertTrue($handled);
        self::assertSame(['CTRL_BREAK_EVENT'], $received);
    }

    /** Leaves unrelated Windows console events to the operating system. */
    public function testUnrelatedConsoleEventIsNotConsumed(): void
    {
        // Arrange
        $received = [];
        $handler = new WindowsConsoleSignalHandler(static function (string $event) use (&$received): void {
            $received[] = $event;
        });

        // Act
        $handled = $handler->handleEvent(2);

        // Assert
        self::assertFalse($handled);
        self::assertSame([], $received);
    }

    /** Registers and unregisters the callback only when the Windows CLI API exists. */
    public function testRegisterAndUnregisterMatchesPlatformCapabilities(): void
    {
        // Arrange
        $handler = new WindowsConsoleSignalHandler(static function (string $event): void {});

        // Act
        $registered = $handler->register();
        $handler->unregister();

        // Assert
        self::assertSame(function_exists('sapi_windows_set_ctrl_handler'), $registered);
    }
}
