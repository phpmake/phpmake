<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Process\ProcessSupervisor;
use PHPUnit\Framework\TestCase;

/** Exercises process-tree discovery and termination against real child processes. */
final class ProcessSupervisorTest extends TestCase
{
    /** Discovers a live descendant process from the operating-system process table. */
    public function testGetDescendantProcessIdsFindsChildProcess(): void
    {
        // Arrange
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX process-table discovery is not available on Windows.');
        }

        $pidFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-descendant-' . bin2hex(random_bytes(8)) . '.pid';
        $childCode = 'sleep(10);';
        $parentCode = '$pipes = []; '
            . '$child = proc_open([PHP_BINARY, "-r", ' . var_export($childCode, true) . '], '
            . '[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes); '
            . 'if (!is_resource($child)) { exit(9); } '
            . 'foreach ($pipes as $pipe) { fclose($pipe); } '
            . '$status = proc_get_status($child); '
            . 'file_put_contents($argv[1], (string) $status["pid"]); '
            . 'sleep(10);';
        $process = proc_open(
            [PHP_BINARY, '-r', $parentCode, $pidFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd(),
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        try {
            $deadline = hrtime(true) + 3_000_000_000;
            while (!is_file($pidFile) && hrtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($pidFile);
            $rootPid = proc_get_status($process)['pid'];
            $childPid = (int) file_get_contents($pidFile);

            // Act
            $descendants = ProcessSupervisor::getDescendantProcessIds($rootPid);

            // Assert
            self::assertGreaterThan(0, $childPid);
            self::assertContains($childPid, $descendants);
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
            if (is_file($pidFile)) {
                $childPid = (int) file_get_contents($pidFile);
                if ($childPid > 0) {
                    @posix_kill($childPid, 9);
                }
                unlink($pidFile);
            }
        }
    }

    /** Terminates a process when process-group tracking is unavailable. */
    public function testTerminateStopsProcessWithoutProcessGroup(): void
    {
        // Arrange
        $process = proc_open(
            [PHP_BINARY, '-r', 'sleep(10);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd(),
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        try {
            $status = proc_get_status($process);
            self::assertTrue($status['running']);

            // Act
            ProcessSupervisor::terminate($process, $status['pid'], false);

            // Assert
            self::assertFalse(proc_get_status($process)['running']);
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }
}
