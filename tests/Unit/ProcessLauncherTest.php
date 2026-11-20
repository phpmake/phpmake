<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Process\ProcessLauncher;
use PhpMake\Process\WindowsProcessLauncher;
use PHPUnit\Framework\TestCase;

/** Tests platform process-launch metadata and Windows command construction. */
final class ProcessLauncherTest extends TestCase
{
    /** Returns a complete process-launch result for the current platform. */
    public function testPrepareReturnsProcessLaunchMetadata(): void
    {
        // Arrange
        $arguments = [PHP_BINARY, '-v'];

        // Act
        $prepared = ProcessLauncher::prepare($arguments);

        // Assert
        self::assertSame(['argv', 'pidPath', 'processGroupCreated'], array_keys($prepared));
        self::assertNotEmpty($prepared['argv']);
        self::assertTrue(is_array($prepared['argv']));
        self::assertTrue($prepared['pidPath'] === null || is_string($prepared['pidPath']));
        self::assertIsBool($prepared['processGroupCreated']);

        if (is_string($prepared['pidPath']) && is_file($prepared['pidPath'])) {
            unlink($prepared['pidPath']);
        }
    }

    /** Builds the PowerShell command and preserves the configured argv payload. */
    public function testWindowsLauncherBuildsEncodedCommand(): void
    {
        // Arrange
        $arguments = [PHP_BINARY, '-r', 'echo "hello";', 'argument with spaces'];

        // Act
        $command = WindowsProcessLauncher::build($arguments);

        // Assert
        self::assertCount(8, $command);
        self::assertStringContainsString('powershell.exe', strtolower($command[0]));
        self::assertSame('-NoProfile', $command[1]);
        self::assertSame('-File', $command[5]);
        self::assertStringEndsWith(
            'Task' . DIRECTORY_SEPARATOR . 'Types' . DIRECTORY_SEPARATOR . 'WindowsJobRunner.ps1',
            $command[6]
        );
        self::assertSame(base64_encode(json_encode($arguments, JSON_THROW_ON_ERROR)), $command[7]);
    }
}
