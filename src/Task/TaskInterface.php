<?php

namespace PhpMake\Task;

/**
 * TaskInterface Interface
 *
 * Defines the contract for all tasks executed within PHPMake tool.
 * Implementing classes must provide methods for execution and identifying task types.
 *
 * @package    PHPMake
 * @subpackage Task
 */
interface TaskInterface
{
    /**
     * Execute the task.
     *
     * Runs task logic and returns a boolean indicating success or failure.
     *
     * @param bool $debug  Enable debug mode for detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of task execution (true if successful, false otherwise).
     */
    public function execute(bool $debug, bool $silent): bool;

    /**
     * Get task type.
     *
     * Returns class name or identifier of task type.
     *
     * @return string The task type.
     */
    public function getType(): string;
}
