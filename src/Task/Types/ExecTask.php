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
            $this->logger->debug('Executing process: ' . implode(' ', array_map('escapeshellarg', $argv)));
        }

        $processOptions = ['bypass_shell' => true];
        $launchArgv = $argv;
        $processGroupCreated = false;
        $hasPosixProcessGroups = PHP_OS_FAMILY !== 'Windows'
            && function_exists('posix_setsid')
            && function_exists('posix_kill');

        if (PHP_OS_FAMILY === 'Windows') {
            $processOptions['create_process_group'] = true;
        }

        $windowsPidPath = null;
        if (PHP_OS_FAMILY === 'Windows') {
            $pidPath = tempnam(sys_get_temp_dir(), 'phpmake-pid-');
            $windowsPidPath = $pidPath === false ? null : $pidPath;
        }

        if (PHP_OS_FAMILY === 'Windows' || $hasPosixProcessGroups) {
            // Keep a stable launcher alive while the configured command runs.
            // On POSIX, establish its session before spawning the target process.
            $launcher = <<<'PHP'
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
if (is_int($childPid) && is_string($pidFile) && $pidFile !== '') {
    @file_put_contents($pidFile, (string) $childPid, LOCK_EX);
}
$exitCode = proc_close($child);
exit($exitCode >= 0 ? $exitCode : 1);
PHP;
            $launchArgv = [
                PHP_BINARY,
                '-r',
                $launcher,
                base64_encode(json_encode($argv, JSON_THROW_ON_ERROR)),
            ];
            if ($windowsPidPath !== null) {
                $launchArgv[] = $windowsPidPath;
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
            if ($windowsPidPath !== null) {
                @unlink($windowsPidPath);
            }
            $this->logger->error('Unable to start command process.');
            return false;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $processPid = proc_get_status($process)['pid'] ?? null;
        $launcherPid = $processPid;
        if ($windowsPidPath !== null) {
            for ($attempt = 0; $attempt < 20; ++$attempt) {
                $childPid = trim((string) @file_get_contents($windowsPidPath));
                if (ctype_digit($childPid) && (int) $childPid > 0) {
                    $processPid = (int) $childPid;
                    break;
                }
                usleep(1000);
            }
        }
        $start = hrtime(true);
        $timedOut = false;
        $observedExitCode = null;
        $outputLimitExceeded = false;
        $stdout = '';
        $stderr = '';

        try {
            while (true) {
                $outputLimitExceeded = self::captureAvailableOutput($pipes[1], $stdout)
                    || self::captureAvailableOutput($pipes[2], $stderr);
                if ($outputLimitExceeded) {
                    $this->terminateProcessTree($process, $processPid, $processGroupCreated, $launcherPid);
                    break;
                }

                $status = proc_get_status($process);
                if ((hrtime(true) - $start) / 1_000_000_000 > $timeout) {
                    $timedOut = true;
                    $this->terminateProcessTree($process, $processPid, $processGroupCreated, $launcherPid);
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
            if ($windowsPidPath !== null) {
                @unlink($windowsPidPath);
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
     * dedicated process group when the POSIX extension is available.
     */
    private function terminateProcessTree($process, ?int $processPid, bool $processGroupCreated, ?int $launcherPid = null): void
    {
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

            // taskkill /T has already handled the tree if either stable PID
            // was terminated successfully; avoid an unnecessary WMI fallback.
            if ($taskkillSucceeded) {
                return;
            }

            // taskkill cannot enumerate descendants after the original parent
            // has already exited. Use the recorded ParentProcessId chain as a fallback.
            $powerShell = $systemRoot . DIRECTORY_SEPARATOR . 'System32'
                . DIRECTORY_SEPARATOR . 'WindowsPowerShell' . DIRECTORY_SEPARATOR
                . 'v1.0' . DIRECTORY_SEPARATOR . 'powershell.exe';
            $script = '$rootPid = ' . $processPid . '; '
                . '$all = @(Get-CimInstance -ClassName Win32_Process); '
                . '$queue = [System.Collections.Generic.Queue[int]]::new(); '
                . '$descendants = [System.Collections.Generic.List[int]]::new(); '
                . '$queue.Enqueue($rootPid); '
                . 'while ($queue.Count -gt 0) { $parent = $queue.Dequeue(); '
                . 'foreach ($item in $all) { if ([int]$item.ParentProcessId -eq $parent) { '
                . '$child = [int]$item.ProcessId; if (-not $descendants.Contains($child)) { '
                . '$descendants.Add($child); $queue.Enqueue($child) } } } }; '
                . 'foreach ($child in $descendants) { Stop-Process -Id $child -Force -ErrorAction SilentlyContinue }; '
                . 'Stop-Process -Id $rootPid -Force -ErrorAction SilentlyContinue';
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
                if ($processWasRunning) {
                    $this->logger->error(sprintf(
                        'Windows process-tree termination failed for PID %s: taskkill=%s PowerShell=%s',
                        (string) $processPid,
                        trim($taskkillError),
                        trim($fallbackError)
                    ));
                }
            } elseif ($processWasRunning) {
                $this->logger->error(sprintf(
                    'Unable to start Windows process-tree termination fallback for PID %s: %s',
                    (string) $processPid,
                    trim($taskkillError)
                ));
            }
        }

        if (
            $processGroupCreated
            && $processPid !== null
            && function_exists('posix_kill')
        ) {
            if (@posix_kill(-$processPid, 15)) {
                usleep(200000);
                if (@posix_kill(-$processPid, 0)) {
                    @posix_kill(-$processPid, 9);
                }
                return;
            }
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
        if (array_key_exists('args', $this->params) && (!is_array($this->params['args']) || array_filter($this->params['args'], static fn($value) => !is_scalar($value)))) {
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
