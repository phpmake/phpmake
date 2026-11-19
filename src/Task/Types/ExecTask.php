<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

/** Runs an external process without invoking a command shell. */
final class ExecTask extends BaseTask
{
    private const int MAX_CAPTURE_BYTES = 1048576;

    /** Starts the configured process, captures output, and enforces its timeout. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $command = trim($this->params['command']);
        $args = $this->params['args'] ?? [];
        $timeout = (float) ($this->params['timeout'] ?? 300);
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 86400) {
            throw new \InvalidArgumentException('Exec timeout must be greater than 0 and at most 86400 seconds.');
        }

        if (!self::hasBalancedQuotes($command)) {
            throw new \InvalidArgumentException('Exec command contains an unbalanced quote.');
        }

        $argv = self::tokenizeCommand($command);
        if ($argv === []) {
            throw new \InvalidArgumentException('Exec command is empty.');
        }

        $argv = array_merge($argv, array_map(static fn($arg): string => (string) $arg, $args));
        if ($this->logger->isDebugEnabled()) {
            $this->logger->debug('Executing process: ' . implode(' ', array_map(escapeshellarg(...), $argv)));
        }

        $processOptions = ['bypass_shell' => true];
        $launchArgv = $argv;
        $processGroupCreated = false;
        $hasPosixExtension = function_exists('posix_setsid') && function_exists('posix_kill');
        $setsidExecutable = null;
        $hasPosixProcessGroups = PHP_OS_FAMILY !== 'Windows' && $hasPosixExtension;
        if (PHP_OS_FAMILY !== 'Windows' && !$hasPosixProcessGroups) {
            $setsidExecutable = self::findExecutable('setsid');
            $hasPosixProcessGroups = $setsidExecutable !== null
                && self::findExecutable('kill') !== null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $processOptions['create_process_group'] = true;
        }

        $processPidPath = null;
        if (
            PHP_OS_FAMILY === 'Windows'
            || (!$hasPosixExtension && $hasPosixProcessGroups)
        ) {
            $pidPath = tempnam(sys_get_temp_dir(), 'phpmake-pid-');
            $processPidPath = $pidPath === false ? null : $pidPath;
        }

        if (PHP_OS_FAMILY === 'Windows' || $hasPosixProcessGroups) {
            // Keep a stable launcher alive while the configured command runs.
            // On POSIX, establish its session before spawning the target process.
            $launcher = <<<'PHP_WRAP'
                $command = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($command)) {
                    exit(126);
                }
                if (function_exists('posix_setsid') && !posix_setsid()) {
                    exit(126);
                }
                $child = proc_open(
                    $command,
                    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
                    $pipes,
                    getcwd(),
                    null,
                    ['bypass_shell' => true]
                );
                if (!is_resource($child)) {
                    exit(127);
                }
                $childPid = proc_get_status($child)['pid'] ?? null;
                $pidFile = $argv[2] ?? null;
                $recordedPid = PHP_OS_FAMILY === 'Windows' ? $childPid : getmypid();
                if (is_int($recordedPid) && is_string($pidFile) && $pidFile !== '') {
                    @file_put_contents($pidFile, (string) $recordedPid, LOCK_EX);
                }
                $exitCode = proc_close($child);
                exit($exitCode >= 0 ? $exitCode : 1);
                PHP_WRAP;
            $launchArgv = [
                PHP_BINARY,
                '-r',
                $launcher,
                base64_encode(json_encode($argv, JSON_THROW_ON_ERROR)),
            ];
            if (!$hasPosixExtension && $setsidExecutable !== null) {
                array_unshift($launchArgv, $setsidExecutable);
            }

            if ($processPidPath !== null) {
                $launchArgv[] = $processPidPath;
            }

            $processGroupCreated = $hasPosixProcessGroups;
        }

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

    #[\Override]
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
        $processWasRunning = proc_get_status($process)['running'] ?? false;

        if (PHP_OS_FAMILY === 'Windows' && $processPid !== null) {
            $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
            $taskkill = $systemRoot . DIRECTORY_SEPARATOR . 'System32'
                . DIRECTORY_SEPARATOR . 'taskkill.exe';
            $taskkillError = '';
            $taskkillSucceeded = false;
            foreach (array_unique(array_filter([$launcherPid, $processPid], static fn($pid): bool => is_int($pid) && $pid > 0)) as $targetPid) {
                $terminator = @proc_open(
                    [$taskkill, '/PID', (string) $targetPid, '/T', '/F'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    null,
                    ['bypass_shell' => true]
                );
                if (!is_resource($terminator)) {
                    $taskkillError .= sprintf('Unable to start taskkill for PID %d. ', $targetPid);
                    continue;
                }

                fclose($pipes[0]);
                stream_get_contents($pipes[1]);
                $taskkillError .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($terminator) === 0) {
                    $taskkillSucceeded = true;
                }
            }

            // taskkill /T normally handles live descendants, but a child may
            // have outlived an intermediate parent. Sweep the captured parent
            // PID chain as well, rooted at both the launcher and target process.
            $powerShell = $systemRoot . DIRECTORY_SEPARATOR . 'System32'
                . DIRECTORY_SEPARATOR . 'WindowsPowerShell' . DIRECTORY_SEPARATOR
                . 'v1.0' . DIRECTORY_SEPARATOR . 'powershell.exe';
            $rootPids = array_values(array_unique(array_filter(
                [$launcherPid, $processPid],
                static fn($pid): bool => is_int($pid) && $pid > 0
            )));
            $rootPidList = implode(',', $rootPids);
            $script = '$rootPids = @(' . $rootPidList . '); '
                . '$all = @(Get-CimInstance -ClassName Win32_Process); '
                . '$queue = [System.Collections.Generic.Queue[int]]::new(); '
                . '$descendants = [System.Collections.Generic.List[int]]::new(); '
                . 'foreach ($rootPid in $rootPids) { $queue.Enqueue([int]$rootPid) }; '
                . 'while ($queue.Count -gt 0) { $parent = $queue.Dequeue(); '
                . 'foreach ($item in $all) { if ([int]$item.ParentProcessId -eq $parent) { '
                . '$child = [int]$item.ProcessId; if (-not $descendants.Contains($child)) { '
                . '$descendants.Add($child); $queue.Enqueue($child) } } } }; '
                . 'foreach ($child in $descendants) { Stop-Process -Id $child -Force -ErrorAction SilentlyContinue }; '
                . 'foreach ($rootPid in $rootPids) { Stop-Process -Id $rootPid -Force -ErrorAction SilentlyContinue }';
            $fallback = @proc_open(
                [$powerShell, '-NoProfile', '-NonInteractive', '-Command', $script],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $fallbackPipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            if (is_resource($fallback)) {
                fclose($fallbackPipes[0]);
                stream_get_contents($fallbackPipes[1]);
                $fallbackError = (string) stream_get_contents($fallbackPipes[2]);
                fclose($fallbackPipes[1]);
                fclose($fallbackPipes[2]);
                if (proc_close($fallback) === 0) {
                    return;
                }

                if ($processWasRunning && !$taskkillSucceeded) {
                    $this->logger->error(sprintf(
                        'Windows process-tree termination failed for PID %s: taskkill=%s PowerShell=%s',
                        (string) $processPid,
                        trim($taskkillError),
                        trim($fallbackError)
                    ));
                }
            } elseif ($processWasRunning && !$taskkillSucceeded) {
                $this->logger->error(sprintf(
                    'Unable to start Windows process-tree termination fallback for PID %s: %s',
                    (string) $processPid,
                    trim($taskkillError)
                ));
            }

            if ($taskkillSucceeded) {
                return;
            }
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

        if (!self::hasBalancedQuotes($this->params['command'])) {
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

    /** Checks that single and double quoted command segments are closed. */
    private static function hasBalancedQuotes(string $command): bool
    {
        $quote = null;
        $length = strlen($command);
        for ($i = 0; $i < $length; ++$i) {
            $character = $command[$i];
            if ($quote !== null) {
                if ($character === '\\' && $i + 1 < $length
                    && ($command[$i + 1] === $quote || $command[$i + 1] === '\\')) {
                    ++$i;
                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            }
        }

        return $quote === null;
    }

    /**
     * Splits a command into argv tokens without interpreting Windows path separators as escapes.
     *
     * @return list<string>
     */
    private static function tokenizeCommand(string $command): array
    {
        preg_match_all('/"((?:\\\\.|[^"])*)"|\'((?:\\\\.|[^\'])*)\'|(\S+)/', $command, $matches, PREG_SET_ORDER);
        $argv = [];

        foreach ($matches as $match) {
            if ($match[1] !== '') {
                // Double-quoted tokens only escape a quote or a literal backslash.
                $token = preg_replace('/\\\\(["\\\\])/', '$1', $match[1]);
            } elseif ($match[2] !== '') {
                // Single-quoted tokens only escape a quote or a literal backslash.
                $token = preg_replace('/\\\\([\'\\\\])/', '$1', $match[2]);
            } else {
                $token = $match[3];
            }

            $argv[] = $token;
        }

        return $argv;
    }

}
