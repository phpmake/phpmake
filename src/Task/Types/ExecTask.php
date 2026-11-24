<?php

namespace PhpMake\Task\Types;

use PhpMake\Process\CommandLineParser;
use PhpMake\Process\ProcessLauncher;
use PhpMake\Process\ProcessSupervisor;
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
        if ($this->logger->isDebugRequested()) {
            // Arguments may contain API keys, passwords, or other credentials.
            $this->logger->debug('Executing external process (arguments omitted).');
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
        $windowsControlHandler = null;
        $previousAsyncSignals = null;
        $previousSignalHandlers = [];

        // On POSIX CLI runtimes, turn SIGINT/SIGTERM into cooperative
        // cancellation so the child process tree can be stopped before exit.
        if (
            PHP_OS_FAMILY === 'Linux'
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
                    $cancelledSignal = $receivedSignal === SIGINT ? 'SIGINT' : 'SIGTERM';
                });
            }
        }

        try {
            if (PHP_OS_FAMILY === 'Windows' && PHP_SAPI === 'cli') {
                $windowsControlHandler = new \PhpMake\Process\WindowsConsoleSignalHandler(
                    static function (string $event) use (&$cancelledSignal): void {
                        $cancelledSignal = $event;
                    }
                );
                $windowsControlHandler->register();
            }

            while (true) {
                // A descendant can create a new POSIX session and escape the
                // original process group. Snapshot descendants periodically so
                // those PIDs can still be terminated if the command times out.
                if (PHP_OS_FAMILY === 'Linux' && is_int($launcherPid) && $launcherPid > 0) {
                    $now = hrtime(true);
                    if ($now - $lastProcessScan >= 50_000_000) {
                        $trackedDescendantPids = array_values(array_unique(array_merge(
                            $trackedDescendantPids,
                            ProcessSupervisor::getDescendantProcessIds($launcherPid)
                        )));
                        $lastProcessScan = $now;
                    }
                }

                // Check process state before probing a full output buffer.
                // Once the child has exited, no overflow byte can arrive, and
                // probing an empty Windows anonymous pipe can block forever.
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $observedExitCode = $status['exitcode'];
                    $stdoutLimitExceeded = self::captureAvailableOutput($pipes[1], $stdout, false);
                    $stderrLimitExceeded = self::captureAvailableOutput($pipes[2], $stderr, false);
                    $outputLimitExceeded = $stdoutLimitExceeded || $stderrLimitExceeded;
                    break;
                }

                // Drain both streams on every pass so one full pipe cannot
                // block the child while the other stream is ignored.
                $stdoutLimitExceeded = self::captureAvailableOutput($pipes[1], $stdout);
                $stderrLimitExceeded = self::captureAvailableOutput($pipes[2], $stderr);
                $outputLimitExceeded = $stdoutLimitExceeded || $stderrLimitExceeded;
                if ($outputLimitExceeded) {
                    ProcessSupervisor::terminate($process, $processPid, $processGroupCreated, $launcherPid, $trackedDescendantPids);
                    break;
                }

                if ($cancelledSignal !== null) {
                    ProcessSupervisor::terminate($process, $processPid, $processGroupCreated, $launcherPid, $trackedDescendantPids);
                    break;
                }

                if ((hrtime(true) - $start) / 1_000_000_000 > $timeout) {
                    $timedOut = true;
                    ProcessSupervisor::terminate($process, $processPid, $processGroupCreated, $launcherPid, $trackedDescendantPids);
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

            if ($windowsControlHandler instanceof \PhpMake\Process\WindowsConsoleSignalHandler) {
                $windowsControlHandler->unregister();
            }
        }

        $closeCode = proc_close($process);
        $exitCode = ($observedExitCode !== null && $observedExitCode >= 0)
            ? $observedExitCode
            : $closeCode;

        if ($outputLimitExceeded) {
            // Do not emit the captured prefix: it can itself be up to 1 MiB
            // and may contain secrets or attacker-controlled terminal output.
            $this->logger->error(sprintf('Command output exceeded the %d-byte per-stream limit.', self::MAX_CAPTURE_BYTES));
            return false;
        }

        foreach (preg_split('/\\R/', rtrim($stdout)) as $line) {
            if ($line !== '') {
                $this->logger->info($line);
            }
        }

        if (trim($stderr) !== '') {
            $this->logger->error(trim($stderr));
        }

        if ($cancelledSignal !== null) {
            $this->logger->error(sprintf('Command cancelled after receiving %s.', $cancelledSignal));
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
    private static function captureAvailableOutput($pipe, string &$buffer, bool $probeOverflow = true): bool
    {
        // A process that has exited cannot produce more output. Avoid the
        // one-byte overflow probe when the buffer is exactly full: on Windows,
        // reading an empty anonymous pipe can block despite non-blocking mode.
        if (!$probeOverflow && strlen($buffer) >= self::MAX_CAPTURE_BYTES) {
            return false;
        }

        // A non-blocking pipe may temporarily have no bytes available while
        // its writer is still active. Do not use feof() as a read-loop guard:
        // on Windows it can report EOF between writes and stop future draining.
        // Read only one bounded chunk per polling pass. Windows anonymous
        // pipes do not consistently honor non-blocking reads when draining
        // until empty; a second fread can wait indefinitely while the child
        // is blocked writing to the same pipe.
        $remaining = self::MAX_CAPTURE_BYTES - strlen($buffer);
        $chunk = fread($pipe, min(65536, $remaining + 1));
        if ($chunk === false || $chunk === '') {
            return false;
        }

        if (strlen($chunk) > $remaining) {
            if ($remaining > 0) {
                $buffer .= substr($chunk, 0, $remaining);
            }

            return true;
        }

        $buffer .= $chunk;
        return false;
    }

    /** Returns the exec task identifier. */
    public function getType(): string
    {
        return 'exec';
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
