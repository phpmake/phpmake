<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

/**
 * ExecTask Class
 *
 * Implements a task for executing shell commands within PHPMake tool.
 * Captures command output and logs execution results.
 *
 * @package    PHPMake
 * @subpackage Task
 */
final class ExecTask extends BaseTask
{
    /**
     * Execute command task.
     *
     * Runs specified shell command and captures its output.
     *
     * @param bool $debug  Enable debug mode for detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of command execution (true if successful, false otherwise).
     *
     * @throws \Exception If required parameters are missing.
     */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $command = $this->params['command'] ?? '';
        $args = $this->params['args'] ?? [];

        if (empty($command)) {
            throw new \Exception("Missing 'command' parameter.");
        }

        // Escape the base command.
        $sanitizedCommand = escapeshellcmd($command);

        // Escape each argument individually and append to command.
        if (is_array($args)) {
            foreach ($args as $arg) {
                $sanitizedCommand .= ' ' . escapeshellarg((string) $arg);
            }
        }

        $output = [];
        $ret = 0;

        if ($this->logger->isDebugEnabled()) {
            $this->logger->debug(sprintf('Executing command: %s', $sanitizedCommand));
        }

        exec($sanitizedCommand, $output, $ret);

        foreach ($output as $line) {
            $this->logger->info($line);
        }

        return $ret === 0;
    }

    /**
     * Get task type identifier.
     *
     * Returns string representation of task type.
     *
     * @return string The task type ('exec').
     */
    #[\Override]
    public function getType(): string
    {
        return 'exec';
    }

    /**
     * Validate required task parameters.
     *
     * Ensures presence of a 'command' parameter before execution.
     *
     * @throws \Exception If required 'command' parameter is missing.
     */
    protected function validateParams(): void
    {
        if (!isset($this->params['command'])) {
            throw new \Exception("Missing 'command' parameter for exec task.");
        }
    }
}
