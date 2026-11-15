<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

final class ExecTask extends BaseTask
{
    protected function runTask(bool $debug, bool $silent): bool
    {
        $command = trim($this->params['command']);
        $args = $this->params['args'] ?? [];
        $timeout = (float) ($this->params['timeout'] ?? 300);
        if ($timeout <= 0 || $timeout > 86400) {
            throw new \InvalidArgumentException("Exec timeout must be between 0 and 86400 seconds.");
        }

        // Tokenize a conventional executable-plus-arguments command string.
        // This preserves existing configs without invoking a shell.
        preg_match_all('/"((?:\\\\.|[^"])*)"|\'((?:\\\\.|[^\'])*)\'|(\S+)/', $command, $matches, PREG_SET_ORDER);
        $argv = [];
        foreach ($matches as $match) {
            $token = $match[1] !== '' ? stripcslashes($match[1]) : ($match[2] !== '' ? stripcslashes($match[2]) : $match[3]);
            $argv[] = $token;
        }
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
            if ($line !== '') $this->logger->info($line);
        }
        if (trim($stderr) !== '') $this->logger->error(trim($stderr));
        if ($timedOut) {
            $this->logger->error(sprintf("Command timed out after %.1f seconds.", $timeout));
            return false;
        }
        return $exitCode === 0;
    }

    #[\Override]
    public function getType(): string { return 'exec'; }

    protected function validateParams(): void
    {
        if (!isset($this->params['command']) || !is_string($this->params['command']) || trim($this->params['command']) === '') {
            throw new \InvalidArgumentException("Exec task requires a non-empty string 'command'.");
        }
        if (isset($this->params['args']) && (!is_array($this->params['args']) || array_filter($this->params['args'], static fn($v) => !is_scalar($v)))) {
            throw new \InvalidArgumentException("Exec task 'args' must be an array of scalar values.");
        }
        if (isset($this->params['timeout']) && (!is_numeric($this->params['timeout']) || (float) $this->params['timeout'] <= 0)) {
            throw new \InvalidArgumentException("Exec task 'timeout' must be a positive number.");
        }
    }
}
