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

        $pipes = [];
        $processOptions = ['bypass_shell' => true];
        $launchArgv = $argv;
        $processGroupCreated = false;
        if (PHP_OS_FAMILY === 'Windows') {
            $processOptions['create_process_group'] = true;
        } elseif (function_exists('posix_setsid') && function_exists('posix_kill')) {
            // Establish a new session inside the child before it can spawn descendants.
            $launcher = <<<'PHP'
$command = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($command) || !posix_setsid()) {
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
$exitCode = proc_close($child);
exit($exitCode >= 0 ? $exitCode : 1);
PHP;
            $launchArgv = [
                PHP_BINARY,
                '-r',
                $launcher,
                base64_encode(json_encode($argv, JSON_THROW_ON_ERROR)),
            ];
            $processGroupCreated = true;
        }
        $process = proc_open(
            $launchArgv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd(),
            null,
            $processOptions
        );
        if (!is_resource($process)) {
            $this->logger->error('Unable to start command process.');
            return false;
        }

        fclose($pipes[0]);
        $processPid = proc_get_status($process)['pid'] ?? null;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $start = microtime(true);
        $timedOut = false;
        $observedExitCode = null;
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $outputLimitExceeded = false;

        do {
            $this->captureOutput($pipes[1], $stdout, $stdoutTruncated);
            $this->captureOutput($pipes[2], $stderr, $stderrTruncated);
            if ($stdoutTruncated || $stderrTruncated) {
                $outputLimitExceeded = true;
                $this->terminateProcessTree($process, $processPid, $processGroupCreated);
                break;
            }
            $status = proc_get_status($process);
            if (microtime(true) - $start > $timeout) {
                $timedOut = true;
                $this->terminateProcessTree($process, $processPid, $processGroupCreated);
                break;
            }
            if (!$status['running']) {
                $observedExitCode = $status['exitcode'];
                break;
            }
            usleep(20000);
        } while (true);

        $this->captureOutput($pipes[1], $stdout, $stdoutTruncated);
        $this->captureOutput($pipes[2], $stderr, $stderrTruncated);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        $exitCode = ($observedExitCode !== null && $observedExitCode >= 0) ? $observedExitCode : $closeCode;

        foreach (preg_split('/\R/', rtrim($stdout)) as $line) {
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
            $this->logger->error(sprintf('Command timed out after %.1f seconds.', $timeout));
            return false;
        }

        return $exitCode === 0;
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
    private function terminateProcessTree($process, ?int $processPid, bool $processGroupCreated): void
    {
        if (PHP_OS_FAMILY === 'Windows' && $processPid !== null) {
            $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
            $taskkill = $systemRoot . DIRECTORY_SEPARATOR . 'System32'
                . DIRECTORY_SEPARATOR . 'taskkill.exe';
            $terminator = @proc_open(
                [$taskkill, '/PID', (string) $processPid, '/T', '/F'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            if (is_resource($terminator)) {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $terminationCode = proc_close($terminator);
                if ($terminationCode === 0) {
                    return;
                }
                $this->logger->error(sprintf(
                    'Windows process-tree termination failed for PID %s (exit %s): %s %s',
                    (string) $processPid,
                    (string) $terminationCode,
                    trim((string) $stdout),
                    trim((string) $stderr)
                ));
            } else {
                $this->logger->error('Unable to start taskkill.exe for process-tree termination.');
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

    /** Reads available process output while enforcing a per-stream memory limit. */
    private function captureOutput($pipe, string &$buffer, bool &$truncated): void
    {
        while (!feof($pipe)) {
            $chunk = fread($pipe, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $remaining = self::MAX_CAPTURE_BYTES - strlen($buffer);
            if ($remaining > 0) {
                $buffer .= substr($chunk, 0, $remaining);
            }
            if (strlen($chunk) > $remaining) {
                $truncated = true;
            }
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
