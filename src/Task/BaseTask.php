<?php

namespace PhpMake\Task;

use PhpMake\Utilities\Logger;

/**
 * BaseTask Abstract Class
 *
 * Provides a foundational structure for task execution in PHPMake tool.
 * Implements the TaskInterface and ensures task validation and execution handling.
 *
 * @package    PHPMake
 * @subpackage Task
 */
abstract class BaseTask implements TaskInterface
{
    /**
     * Constructor
     *
     * Initializes task with provided parameters and a logger.
     *
     * @param array  $params Task parameters.
     * @param Logger $logger Logger instance.
     */
    public function __construct(protected array $params, protected Logger $logger)
    {
        $this->validateParams();
    }

    /**
     * Execute the task.
     *
     * Handles execution of task while managing errors and logging output.
     *
     * @param bool $debug  Debug flag to enable detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of task execution (true if successful, false otherwise).
     */
    public function execute(bool $debug, bool $silent): bool
    {
        try {
            return $this->runTask($debug, $silent); // Pass flags to `runTask()`.
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            return false;
        }
    }

    /**
     * Get task type.
     *
     * Returns the class name of task.
     *
     * @return string The class name of the task.
     */
    public function getType(): string
    {
        return static::class;
    }

    /**
     * Execute task logic.
     *
     * Abstract method that must be implemented by child classes
     * to define specific task execution logic.
     *
     * @param bool $debug  Debug flag to enable detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of task execution.
     */
    abstract protected function runTask(bool $debug, bool $silent): bool;

    /**
     * Validate task parameters.
     *
     * Abstract method that performs validation on the task parameters to ensure correct execution.
     */
    abstract protected function validateParams();
}
