<?php

declare(strict_types=1);

namespace PhpMake\Tests\Support;

/** Runs test subprocesses with bounded execution time and failure-safe cleanup. */
trait ProcessTestHelper
{
    /**
     * Runs a subprocess with file-backed output capture to avoid pipe-buffer deadlocks.
     *
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool}
     */
    private function runTestProcess(array $command, string $workingDirectory, float $timeoutSeconds = 15.0): array
    {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'phpmake-test-stdout-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'phpmake-test-stderr-');

        if ($stdoutPath === false || $stderrPath === false) {
            if (is_string($stdoutPath)) {
                unlink($stdoutPath);
            }

            if (is_string($stderrPath)) {
                unlink($stderrPath);
            }

            throw new \RuntimeException('Unable to create temporary subprocess output files.');
        }

        $process = null;
        $timedOut = false;
        $exitCode = -1;

        try {
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', $stdoutPath, 'w'],
                    2 => ['file', $stderrPath, 'w'],
                ],
                $pipes,
                $workingDirectory,
                null,
                ['bypass_shell' => true]
            );

            if (!is_resource($process)) {
                throw new \RuntimeException('Unable to start test subprocess.');
            }

            fclose($pipes[0]);
            $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                if (hrtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process, 9);
                    break;
                }

                usleep(10000);
            } while (true);

            if ($timedOut) {
                $status = proc_get_status($process);
                $killDeadline = hrtime(true) + 1_000_000_000;
                while ($status['running'] && hrtime(true) < $killDeadline) {
                    usleep(10000);
                    $status = proc_get_status($process);
                }
            }

            $closedExitCode = proc_close($process);
            $process = null;

            if ($exitCode < 0 && $closedExitCode >= 0) {
                $exitCode = $closedExitCode;
            }

            return [
                'exitCode' => $exitCode,
                'stdout' => (string) file_get_contents($stdoutPath),
                'stderr' => (string) file_get_contents($stderrPath),
                'timedOut' => $timedOut,
            ];
        } finally {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }

                proc_close($process);
            }

            unlink($stdoutPath);
            unlink($stderrPath);
        }
    }
}
