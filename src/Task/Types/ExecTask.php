<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

/** Runs an external process without invoking a command shell. */
final class ExecTask extends BaseTask
{
    /** Starts the configured process, captures output, and enforces its timeout. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $command = trim($this->params['command']);
        $args = $this->params['args'] ?? [];
        $timeout = (float) ($this->params['timeout'] ?? 300);
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 86400) {
            throw new \InvalidArgumentException('Exec timeout must be greater than 0 and at most 86400 seconds.');
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
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, getcwd(), null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            $this->logger->error('Unable to start command process.');
            return false;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $start = microtime(true);
        $timedOut = false;
        $observedExitCode = null;

        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                $observedExitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) - $start > $timeout) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(200000);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(20000);
        } while (true);

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
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

    /** Validates the command, argument list, and optional timeout. */
    protected function validateParams(): void
    {
        if (!isset($this->params['command']) || !is_string($this->params['command']) || trim($this->params['command']) === '') {
            throw new \InvalidArgumentException("Exec task requires a non-empty string 'command'.");
        }
        if (isset($this->params['args']) && (!is_array($this->params['args']) || array_filter($this->params['args'], static fn($value) => !is_scalar($value)))) {
            throw new \InvalidArgumentException("Exec task 'args' must be an array of scalar values.");
        }
        if (isset($this->params['timeout']) && (
            !is_numeric($this->params['timeout'])
            || (float) $this->params['timeout'] <= 0
            || (float) $this->params['timeout'] > 86400
        )) {
            throw new \InvalidArgumentException("Exec task 'timeout' must be greater than 0 and at most 86400 seconds.");
        }
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
