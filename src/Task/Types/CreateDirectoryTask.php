<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

/**
 * CreateDirectoryTask Class
 *
 * Implements a task for creating directories within PHPMake tool.
 * Ensures the directory is created with appropriate permissions.
 *
 * @package    PHPMake
 * @subpackage Task
 */
final class CreateDirectoryTask extends BaseTask
{
    /**
     * Execute directory creation task.
     *
     * Creates specified directory with appropriate permissions.
     *
     * @param bool $debug  Enable debug mode for detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of directory creation operation (true if successful, false otherwise).
     *
     * @throws \Exception If required 'name' parameter is missing.
     */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $dirName = $this->params['name'] ?? '';

        if (empty($dirName)) {
            throw new \Exception("Missing 'name' parameter.");
        }

        if (mkdir($dirName, 0o777, true)) {
            $this->logger->debug(sprintf("Created directory '%s'.", $dirName));
            return true;
        }

        return false;
    }

    protected function validateParams(): void
    {
        if (!isset($this->params['name'])) {
            throw new \Exception("Missing 'name' parameter for create_directory task.");
        }
    }

    /**
     * Get task type identifier.
     *
     * Returns string representation of task type.
     *
     * @return string The task type ('create_directory').
     */
    #[\Override]
    public function getType(): string
    {
        return 'create_directory';
    }
}
