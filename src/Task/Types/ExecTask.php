<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

final class ExecTask extends BaseTask
{
    protected function runTask(bool $debug, bool $silent): bool
    {
        $command = $this->params['command'];
        $args = $this->params['args'] ?? [];
        $timeout = (float) ($this->params['timeout'] ?? 300);
        if ($timeout <= 0 || $timeout > 86400) {
            throw new \InvalidArgumentException("Exec timeout must be between 0 and 86400 seconds.");
        }
        // Use argv-form proc_open: no shell interpolation or quoting layer.
        $argv = array_merge([$command], array_map(static fn($arg): string => (string) $arg, $args));
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes, getcwd(), null, ['bypass_shell' => true]);
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
        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $start > $timeout) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(200000);
                $status = proc_get_status($process);
                if ($status['running']) {
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
        $exitCode = proc_close($process);
        foreach (preg_split('/\R/', rtrim($stdout)) as $line) {
            if ($line !== '') $this->logger->info($line);
        }
        if ($stderr !== '') {
            $this->logger->error(trim($stderr));
        }
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
