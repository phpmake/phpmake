<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

/**
 * EchoTask Class
 *
 * Implements a task that outputs a message to the logger within PHPMake tool.
 * This task is useful for providing feedback or status updates during execution.
 *
 * @package    PHPMake
 * @subpackage Task
 */
final class EchoTask extends BaseTask
{
    /**
     * Execute echo task.
     *
     * Outputs a message to logger.
     *
     * @param bool $debug  Enable debug mode for detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Always returns true since task simply logs a message.
     */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $message = $this->params['message'] ?? '';

        if ($this->logger->isDebugEnabled()) {
            $this->logger->debug(sprintf('Echoing message: %s.', $message));
        }

        $this->logger->info($message);
        return true;
    }

    /**
     * Get task type identifier.
     *
     * Returns string representation of task type.
     *
     * @return string The task type ('echo').
     */
    public function getType(): string
    {
        return 'echo';
    }

    /**
     * Validate required task parameters.
     *
     * Ensures presence of a 'message' parameter before execution.
     *
     * @throws \Exception If required 'message' parameter is missing.
     */
    protected function validateParams()
    {
        if (!isset($this->params['message'])) {
            throw new \Exception("Missing 'message' parameter for echo task.");
        }
    }
}
