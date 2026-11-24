<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Process\PosixProcessLauncher;
use PHPUnit\Framework\TestCase;

/** Tests POSIX process-group launcher preparation using the real host environment. */
final class PosixProcessLauncherTest extends TestCase
{
    /** Prepares valid launcher metadata and preserves the original argv payload. */
    public function testPrepareReturnsConsistentProcessGroupMetadata(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The POSIX process launcher is available only on Linux.');
        }

        $arguments = [PHP_BINARY, '-r', 'echo "process";', 'argument with spaces'];

        // Act
        $prepared = PosixProcessLauncher::prepare($arguments);

        try {
            // Assert
            self::assertSame(['argv', 'pidPath', 'processGroupCreated'], array_keys($prepared));
            self::assertNotEmpty($prepared['argv']);
            self::assertIsBool($prepared['processGroupCreated']);
            self::assertTrue(
                $prepared['pidPath'] === null || is_string($prepared['pidPath'])
            );

            if ($prepared['processGroupCreated']) {
                self::assertContains(
                    base64_encode(json_encode($arguments, JSON_THROW_ON_ERROR)),
                    $prepared['argv']
                );
            } else {
                self::assertSame($arguments, $prepared['argv']);
                self::assertNull($prepared['pidPath']);
            }
        } finally {
            if (is_string($prepared['pidPath']) && is_file($prepared['pidPath'])) {
                unlink($prepared['pidPath']);
            }
        }
    }
}
