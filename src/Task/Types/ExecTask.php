<?php

namespace PhpMake\Task\Types;

use PhpMake\Process\CommandLineParser;
use PhpMake\Process\ProcessLauncher;
use PhpMake\Task\BaseTask;

/** Runs an external process without invoking a command shell. */
final class ExecTask extends BaseTask
{
    private const MAX_CAPTURE_BYTES = 1048576;

    /** Starts the configured process, captures output, and enforces its timeout. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $command = trim($this->params['command']);
        $args = $this->params['args'] ?? [];
        $timeout = (float) ($this->params['timeout'] ?? 300);
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 86400) {
            throw new \InvalidArgumentException('Exec timeout must be greater than 0 and at most 86400 seconds.');
        }

        if (!CommandLineParser::hasBalancedQuotes($command)) {
            throw new \InvalidArgumentException('Exec command contains an unbalanced quote.');
        }

        $argv = CommandLineParser::tokenize($command);
        if ($argv === []) {
            throw new \InvalidArgumentException('Exec command is empty.');
        }

        $argv = array_merge($argv, array_map(static fn($arg): string => (string) $arg, $args));
        if ($this->logger->isDebugEnabled()) {
            $this->logger->debug('Executing process: ' . implode(' ', array_map('escapeshellarg', $argv)));
        }

        $processOptions = ['bypass_shell' => true];
        $launch = ProcessLauncher::prepare($argv);
        $launchArgv = $launch['argv'];
        $processPidPath = $launch['pidPath'];
        $processGroupCreated = $launch['processGroupCreated'];

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            $launchArgv,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            getcwd(),
            null,
            $processOptions
        );
        if (!is_resource($process)) {
            if ($processPidPath !== null) {
                @unlink($processPidPath);
            }

            $this->logger->error('Unable to start command process.');
            return false;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $processPid = proc_get_status($process)['pid'] ?? null;
        $launcherPid = $processPid;
        if ($processPidPath !== null) {
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $childPid = trim((string) @file_get_contents($processPidPath));
                if (preg_match('/^[0-9]+$/D', $childPid) === 1 && (int) $childPid > 0) {
                    $processPid = (int) $childPid;
                    break;
                }

                usleep(1000);
            }
        }

        $start = hrtime(true);
        $trackedDescendantPids = [];
        $lastProcessScan = 0;
        $timedOut = false;
        $observedExitCode = null;
        $outputLimitExceeded = false;
        $stdout = '';
        $stderr = '';
        $cancelledSignal = null;
        $previousAsyncSignals = null;
        $previousSignalHandlers = [];

        // On POSIX CLI runtimes, turn SIGINT/SIGTERM into cooperative
        // cancellation so the child process tree can be stopped before exit.
        if (
            PHP_OS_FAMILY !== 'Windows'
            && PHP_SAPI === 'cli'
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals')
        ) {
            $previousAsyncSignals = pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                $previousSignalHandlers[$signal] = function_exists('pcntl_signal_get_handler')
                    ? pcntl_signal_get_handler($signal)
                    : SIG_DFL;
                pcntl_signal($signal, static function (int $receivedSignal) use (&$cancelledSignal): void {
                    $cancelledSignal = $receivedSignal;
                });
            }
        }

        try {
            while (true) {
                // A descendant can create a new POSIX session and escape the
                // original process group. Snapshot descendants periodically so
                // those PIDs can still be terminated if the command times out.
                if (PHP_OS_FAMILY !== 'Windows' && is_int($launcherPid) && $launcherPid > 0) {
                    $now = hrtime(true);
                    if ($now - $lastProcessScan >= 50_000_000) {
                        $trackedDescendantPids = array_values(array_unique(array_merge(
                            $trackedDescendantPids,
                            self::getDescendantProcessIds($launcherPid)
                        )));
                        $lastProcessScan = $now;
                    }
                }

                $outputLimitExceeded = self::captureAvailableOutput($pipes[1], $stdout)
                    || self::captureAvailableOutput($pipes[2], $stderr);
                if ($outputLimitExceeded) {
                    $this->terminateProcessTree($process, $processPid, $processGroupCreated, $launcherPid, $trackedDescendantPids);
                    break;
                }

                if ($cancelledSignal !== null) {
                    $this->terminateProcessTree($process, $processPid, $processGroupCreated, $launcherPid, $trackedDescendantPids);
                    break;
                }

                $status = proc_get_status($process);
                if ((hrtime(true) - $start) / 1_000_000_000 > $timeout) {
                    $timedOut = true;
                    $this->terminateProcessTree($process, $processPid, $processGroupCreated, $launcherPid, $trackedDescendantPids);
                    break;
                }

                if (!$status['running']) {
                    $observedExitCode = $status['exitcode'];
                    $outputLimitExceeded = self::captureAvailableOutput($pipes[1], $stdout)
                        || self::captureAvailableOutput($pipes[2], $stderr);
                    break;
                }

                usleep(10000);
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if ($processPidPath !== null) {
                @unlink($processPidPath);
            }

            if ($previousAsyncSignals !== null) {
                foreach ($previousSignalHandlers as $signal => $handler) {
                    pcntl_signal((int) $signal, $handler);
                }

                pcntl_async_signals($previousAsyncSignals);
            }
        }

        $closeCode = proc_close($process);
        $exitCode = ($observedExitCode !== null && $observedExitCode >= 0)
            ? $observedExitCode
            : $closeCode;

        foreach (preg_split('/\\R/', rtrim($stdout)) as $line) {
            if ($line !== '') {
                $this->logger->info($line);
            }
        }

        if (trim($stderr) !== '') {
            $this->logger->error(trim($stderr));
        }

        if ($outputLimitExceeded) {
            $this->logger->error(sprintf('Command output exceeded the %d-byte per-stream limit.', self::MAX_CAPTURE_BYTES));
            return false;
        }

        if ($cancelledSignal !== null) {
            $signalName = $cancelledSignal === SIGINT ? 'SIGINT' : 'SIGTERM';
            $this->logger->error(sprintf('Command cancelled after receiving %s.', $signalName));
            return false;
        }

        if ($timedOut) {
            $this->logger->error(sprintf(
                'Command timed out after %.1f seconds (PID %s).',
                $timeout,
                (string) $processPid
            ));
            return false;
        }

        return $exitCode === 0;
    }


    /** Finds an executable in PATH without invoking a command shell. */
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

    /**
     * Returns all currently observable descendants of a process, including
     * descendants that have moved into a different POSIX session.
     *
     * @return list<int>
     */
    private static function getDescendantProcessIds(int $rootPid): array
    {
        $ps = self::findExecutable('ps');
        if ($ps === null) {
            return [];
        }

        $process = @proc_open(
            [$ps, '-axo', 'pid=,ppid='],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
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
        foreach (preg_split('/\\R/', trim($output)) ?: [] as $line) {
            if (preg_match('/^\\s*(\\d+)\\s+(\\d+)\\s*$/D', $line, $matches) !== 1) {
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

    /** Sends a signal to an individual POSIX process without invoking a shell. */
    private static function signalProcess(string $signal, int $processId): bool
    {
        $kill = self::findExecutable('kill');
        if ($kill === null) {
            return false;
        }

        $terminator = @proc_open(
            [$kill, '-' . $signal, (string) $processId],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        return is_resource($terminator) && proc_close($terminator) === 0;
    }

    /** Sends a signal to a POSIX process group using the kill executable. */
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

    /**
     * Captures currently available pipe data without allowing either stream
     * buffer to exceed MAX_CAPTURE_BYTES. No output is spooled to disk.
     */
    private static function captureAvailableOutput($pipe, string &$buffer): bool
    {
        while (!feof($pipe)) {
            $chunk = fread($pipe, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $remaining = self::MAX_CAPTURE_BYTES - strlen($buffer);
            if (strlen($chunk) > $remaining) {
                if ($remaining > 0) {
                    $buffer .= substr($chunk, 0, $remaining);
                }

                return true;
            }

            $buffer .= $chunk;
        }

        return false;
    }

    /** Returns the exec task identifier. */
    public function getType(): string
    {
        return 'exec';
    }

    /**
     * Terminates the process and descendants without invoking a shell.
     *
     * Windows uses taskkill's process-tree switch. POSIX platforms use a
     * dedicated process group when ext-posix or the setsid/kill utilities are available.
     */
    private function terminateProcessTree(
        $process,
        ?int $processPid,
        bool $processGroupCreated,
        ?int $launcherPid = null,
        array $trackedDescendantPids = []
    ): void {
        if (PHP_OS_FAMILY === 'Windows') {
            // The PowerShell launcher owns the Job Object. Terminating it
            // closes the last Job handle, enforcing KILL_ON_JOB_CLOSE for the
            // target and every descendant, including detached descendants.
            proc_terminate($process, 15);
            usleep(100000);
            if (proc_get_status($process)['running'] ?? false) {
                proc_terminate($process, 9);
            }

            return;
        }

        // The launcher creates the session, so the recorded launcher PID is
        // the process-group ID. The configured command PID is only a member.
        // Also signal tracked descendants individually: a descendant may have
        // called setsid() and therefore no longer belong to this process group.
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

        // Best-effort fallback when process groups are unavailable.
        proc_terminate($process, 15);
        usleep(200000);
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
    }


    /** Validates the command, argument list, and optional timeout. */
    protected function validateParams(): void
    {
        if (!isset($this->params['command']) || !is_string($this->params['command']) || trim($this->params['command']) === '') {
            throw new \InvalidArgumentException("Exec task requires a non-empty string 'command'.");
        }

        if (!CommandLineParser::hasBalancedQuotes($this->params['command'])) {
            throw new \InvalidArgumentException('Exec command contains an unbalanced quote.');
        }

        if (array_key_exists('args', $this->params) && (!is_array($this->params['args']) || array_filter($this->params['args'], static fn($value): bool => !is_scalar($value)))) {
            throw new \InvalidArgumentException("Exec task 'args' must be an array of scalar values.");
        }

        if (array_key_exists('timeout', $this->params) && (
            (!is_int($this->params['timeout']) && !is_float($this->params['timeout']))
            || !is_finite((float) $this->params['timeout'])
            || (float) $this->params['timeout'] <= 0
            || (float) $this->params['timeout'] > 86400
        )) {
            throw new \InvalidArgumentException("Exec task 'timeout' must be greater than 0 and at most 86400 seconds.");
        }
    }

}
