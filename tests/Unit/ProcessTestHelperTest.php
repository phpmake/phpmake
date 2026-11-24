<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Tests\Support\ProcessTestHelper;
use PHPUnit\Framework\TestCase;

/** Verifies bounded subprocess execution, output capture, and process-tree cleanup. */
final class ProcessTestHelperTest extends TestCase
{
    use ProcessTestHelper;

    /** Captures stdout and stderr without depending on pipe-buffer capacity. */
    public function testRunTestProcessCapturesOutputStreams(): void
    {
        // Arrange
        $code = 'fwrite(STDOUT, str_repeat("o", 131072)); fwrite(STDERR, "diagnostic");';

        // Act
        $result = $this->runTestProcess([PHP_BINARY, '-r', $code], getcwd() ?: sys_get_temp_dir());

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
        $result = $this->runTestProcess(
            [PHP_BINARY, '-r', 'sleep(10);'],
            getcwd() ?: sys_get_temp_dir(),
            0.1
        );
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        // Assert
        self::assertTrue($result['timedOut']);
        self::assertLessThan(3.0, $elapsedSeconds);
    }

    /** Terminates descendants as well as the root process when a subprocess times out. */
    public function testRunTestProcessTerminatesDescendantsOnTimeout(): void
    {
        // Arrange
        $pidFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-child-' . bin2hex(random_bytes(8)) . '.pid';
        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-child-marker-' . bin2hex(random_bytes(8));
        $releaseFile = $marker . '.release';
        $childCode = 'while (!is_file($argv[2])) { usleep(10000); } '
            . 'file_put_contents($argv[1], "survived");';
        $parentCode = '$pipes = []; '
            . '$child = proc_open([PHP_BINARY, "-r", ' . var_export($childCode, true) . ', '
            . var_export($marker, true) . ', ' . var_export($releaseFile, true) . '], '
            . '[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes); '
            . 'if (!is_resource($child)) { exit(9); } '
            . 'foreach ($pipes as $pipe) { fclose($pipe); } '
            . 'file_put_contents(' . var_export($pidFile, true) . ', (string) proc_get_status($child)["pid"]); '
            . 'sleep(10);';

        try {
            // Act
            $result = $this->runTestProcess(
                [PHP_BINARY, '-r', $parentCode],
                getcwd() ?: sys_get_temp_dir(),
                0.5
            );
            self::assertTrue(touch($releaseFile));
            $deadline = hrtime(true) + 1_000_000_000;
            while (!is_file($marker) && hrtime(true) < $deadline) {
                usleep(10000);
            }

            // Assert
            self::assertTrue($result['timedOut']);
            self::assertFileExists($pidFile);
            self::assertFileDoesNotExist($marker);
        } finally {
            if (is_file($pidFile)) {
                $childPid = (int) file_get_contents($pidFile);
                if ($childPid > 0 && function_exists('posix_kill')) {
                    @posix_kill($childPid, 9);
                }

                $this->removeTestFile($pidFile);
            }

            foreach ([$marker, $releaseFile] as $path) {
                if (is_file($path)) {
                    $this->removeTestFile($path);
                }
            }
        }
    }

    /** Passes an explicitly supplied environment to the child process. */
    public function testRunTestProcessPassesExplicitEnvironment(): void
    {
        // Arrange
        $code = 'echo getenv("PHPMAKE_TEST_VALUE");';

        // Act
        $result = $this->runTestProcess(
            [PHP_BINARY, '-r', $code],
            getcwd() ?: sys_get_temp_dir(),
            5.0,
            ['PHPMAKE_TEST_VALUE' => 'isolated-value']
        );

        // Assert
        self::assertFalse($result['timedOut']);
        self::assertSame(0, $result['exitCode']);
        self::assertSame('isolated-value', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    /** Rejects zero and non-finite subprocess deadlines before launching a process. */
    public function testRunTestProcessRejectsInvalidTimeouts(): void
    {
        // Arrange
        $invalidTimeouts = [0.0, -1.0, INF, NAN];

        // Act
        foreach ($invalidTimeouts as $timeout) {
            try {
                $this->runTestProcess([PHP_BINARY, '-r', 'exit(0);'], getcwd() ?: sys_get_temp_dir(), $timeout);
                self::fail('An invalid timeout must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                // Assert
                self::assertSame(
                    'The subprocess timeout must be a finite positive number.',
                    $exception->getMessage()
                );
            }
        }
    }

    /** Rejects invalid command and working-directory inputs before process creation. */
    public function testRunTestProcessRejectsInvalidCommandAndWorkingDirectory(): void
    {
        // Arrange
        $invalidCalls = [
            [[], getcwd() ?: sys_get_temp_dir()],
            [[PHP_BINARY, '-r', 'exit(0);'], sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-' . bin2hex(random_bytes(4))],
            [[PHP_BINARY, 42], getcwd() ?: sys_get_temp_dir()],
        ];

        // Act
        foreach ($invalidCalls as [$command, $workingDirectory]) {
            try {
                $this->runTestProcess($command, $workingDirectory);
                self::fail('Invalid process arguments must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                // Assert
                self::assertInstanceOf(\InvalidArgumentException::class, $exception);
            }
        }
    }
}
