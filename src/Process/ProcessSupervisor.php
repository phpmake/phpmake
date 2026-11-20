<?php

declare(strict_types=1);

namespace PhpMake\Process;

/** Supervises and terminates process trees without invoking a command shell. */
final class ProcessSupervisor
{
    /** @return list<int> */
    public static function getDescendantProcessIds(int $rootPid): array
    {
        $ps = self::findExecutable('ps');
        if ($ps === null) {
            return [];
        }

        $process = @proc_open(
            [$ps, '-axo', 'pid=,ppid='],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return [];
        }

        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return [];
        }

        $childrenByParent = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/D', $line, $matches) !== 1) {
                continue;
            }

            $pid = (int) $matches[1];
            $parentPid = (int) $matches[2];
            if ($pid > 0 && $parentPid > 0) {
                $childrenByParent[$parentPid][] = $pid;
            }
        }

        $queue = [$rootPid];
        $visited = [$rootPid => true];
        $descendants = [];
        while ($queue !== []) {
            $parentPid = array_shift($queue);
            foreach ($childrenByParent[$parentPid] ?? [] as $childPid) {
                if (isset($visited[$childPid])) {
                    continue;
                }

                $visited[$childPid] = true;
                $descendants[] = $childPid;
                $queue[] = $childPid;
            }
        }

        return $descendants;
    }

    /**
     * Terminates the configured process tree and all descendants observed by the caller.
     *
     * @param resource $process
     * @param list<int> $trackedDescendantPids
     */
    public static function terminate(
        $process,
        ?int $processPid,
        bool $processGroupCreated,
        ?int $launcherPid = null,
        array $trackedDescendantPids = []
    ): void {
        if (PHP_OS_FAMILY === 'Windows') {
            proc_terminate($process, 15);
            usleep(100000);
            if (proc_get_status($process)['running'] ?? false) {
                proc_terminate($process, 9);
            }

            return;
        }

        $processGroupId = $launcherPid ?? $processPid;
        $groupTerminated = false;
        if ($processGroupCreated && $processGroupId !== null) {
            if (function_exists('posix_kill')) {
                $groupTerminated = @posix_kill(-$processGroupId, 15);
            } else {
                $groupTerminated = self::signalProcessGroup('TERM', $processGroupId);
            }
        }

        foreach ($trackedDescendantPids as $descendantPid) {
            if (!is_int($descendantPid) || $descendantPid <= 0) {
                continue;
            }

            if (function_exists('posix_kill')) {
                @posix_kill($descendantPid, 15);
            } else {
                self::signalProcess('TERM', $descendantPid);
            }
        }

        if ($groupTerminated || $trackedDescendantPids !== []) {
            usleep(200000);
            if ($processGroupCreated && $processGroupId !== null) {
                if (function_exists('posix_kill')) {
                    if (@posix_kill(-$processGroupId, 0)) {
                        @posix_kill(-$processGroupId, 9);
                    }
                } else {
                    self::signalProcessGroup('KILL', $processGroupId);
                }
            }

            foreach ($trackedDescendantPids as $descendantPid) {
                if (!is_int($descendantPid) || $descendantPid <= 0) {
                    continue;
                }

                if (function_exists('posix_kill')) {
                    if (@posix_kill($descendantPid, 0)) {
                        @posix_kill($descendantPid, 9);
                    }
                } else {
                    self::signalProcess('KILL', $descendantPid);
                }
            }

            return;
        }

        proc_terminate($process, 15);
        usleep(200000);
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
    }

    private static function signalProcess(string $signal, int $processId): bool
    {
        $kill = self::findExecutable('kill');
        if ($kill === null) {
            return false;
        }

        $terminator = @proc_open(
            [$kill, '-' . $signal, (string) $processId],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        return is_resource($terminator) && proc_close($terminator) === 0;
    }

    private static function signalProcessGroup(string $signal, int $processGroupId): bool
    {
        $kill = self::findExecutable('kill');
        if ($kill === null) {
            return false;
        }

        $terminator = @proc_open(
            [$kill, '-' . $signal, '-' . $processGroupId],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        return is_resource($terminator) && proc_close($terminator) === 0;
    }

    private static function findExecutable(string $name): ?string
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
