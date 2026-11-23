<?php

declare(strict_types=1);

namespace PhpMake\\Tests\\Support;

use PhpMake\\Process\\ProcessSupervisor;

/** Runs test subprocesses with bounded execution time and failure-safe cleanup. */
trait ProcessTestHelper
{
    /**
     * Runs a subprocess with file-backed output capture to avoid pipe-buffer deadlocks.
     *
     * @param list<string> $command
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool}
     */
    private function runTestProcess(array $command, string $workingDirectory, float $timeoutSeconds = 15.0): array
    {
        if ($command === [] || array_values($command) !== $command || $command[0] === '') {
            throw new \\InvalidArgumentException('The subprocess command must be a non-empty list of strings.');
        }

        foreach ($command as $argument) {
            if (!is_string($argument)) {
                throw new \\InvalidArgumentException('Every subprocess command argument must be a string.');
            }
        }

        if (!is_dir($workingDirectory)) {
            throw new \\InvalidArgumentException('The subprocess working directory must exist.');
        }

        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new \\InvalidArgumentException('The subprocess timeout must be a finite positive number.');
        }

        $stdoutPath = tempnam(sys_get_temp_dir(), 'phpmake-test-stdout-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'phpmake-test-stderr-');

        if ($stdoutPath === false || $stderrPath === false) {
            if (is_string($stdoutPath)) {
                unlink($stdoutPath);
            }

            if (is_string($stderrPath)) {
                unlink($stderrPath);
            }

            throw new \\RuntimeException('Unable to create temporary subprocess output files.');
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
                throw new \\RuntimeException('Unable to start test subprocess.');
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
                    $this->terminateTestProcessTree($process, (int) $status['pid'], $stdoutPath, $stderrPath);
                    break;
                }

                usleep(10000);
            } while (true);

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
                    $this->terminateTestProcessTree($process, (int) $status['pid'], $stdoutPath, $stderrPath);
                }

                proc_close($process);
            }

            unlink($stdoutPath);
            unlink($stderrPath);
        }
    }

    /**
     * Terminates the process tree before returning control to the test runner.
     *
     * @param resource $process
     */
    private function terminateTestProcessTree($process, int $processId, string $stdoutPath, string $stderrPath): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $taskkill = $this->findTestExecutable('taskkill.exe');
            if ($taskkill !== null) {
                $terminator = @proc_open(
                    [$taskkill, '/PID', (string) $processId, '/T', '/F'],
                    [
                        0 => ['file', 'NUL', 'r'],
                        1 => ['file', $stdoutPath, 'a'],
                        2 => ['file', $stderrPath, 'a'],
                    ],
                    $pipes,
                    null,
                    null,
                    ['bypass_shell' => true]
                );

                if (is_resource($terminator)) {
                    proc_close($terminator);
                }
            }

            if (proc_get_status($process)['running'] ?? false) {
                proc_terminate($process, 9);
            }

            return;
        }

        $descendants = ProcessSupervisor::getDescendantProcessIds($processId);
        ProcessSupervisor::terminate($process, $processId, false, null, $descendants);

        if (proc_get_status($process)['running'] ?? false) {
            proc_terminate($process, 9);
        }
    }

    private function findTestExecutable(string $name): ?string
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
