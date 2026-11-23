<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Tests\Support\ProcessTestHelper;
use PHPUnit\Framework\TestCase;

/** Verifies bounded subprocess execution and output capture for integration tests. */
final class ProcessTestHelperTest extends TestCase
{
    use ProcessTestHelper;

    /** Captures stdout and stderr without depending on pipe-buffer capacity. */
    public function testRunTestProcessCapturesOutputStreams(): void
    {
        // Arrange
        $code = 'fwrite(STDOUT, str_repeat("o", 131072)); fwrite(STDERR, "diagnostic");';

        // Act
        $result = $this->runTestProcess([PHP_BINARY, '-r', $code], (getcwd() ?: sys_get_temp_dir()));

        // Assert
        self::assertFalse($result['timedOut']);
        self::assertSame(0, $result['exitCode']);
        self::assertSame(131072, strlen($result['stdout']));
        self::assertSame('diagnostic', $result['stderr']);
    }

    /** Terminates a subprocess that exceeds its configured execution deadline. */
    public function testRunTestProcessTerminatesTimedOutSubprocess(): void
    {
        // Arrange
        $startedAt = hrtime(true);

        // Act
        $result = $this->runTestProcess([PHP_BINARY, '-r', 'sleep(10);'], (getcwd() ?: sys_get_temp_dir()), 0.1);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        // Assert
        self::assertTrue($result['timedOut']);
        self::assertLessThan(3.0, $elapsedSeconds);
    }
}
