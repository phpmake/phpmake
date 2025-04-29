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
     * @throws \Exception If required 'dir' parameter is missing.
     */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $dir = $this->params['dir'] ?? '';

        if (empty($dir)) {
            throw new \Exception("Missing 'dir' parameter.");
        }

        if (mkdir($dir, 0777, true)) {
            $this->logger->debug("Created directory '{$dir}'.");
            return true;
        }

        return false;
    }

    /**
     * Get task type identifier.
     *
     * Returns string representation of task type.
     *
     * @return string The task type ('create_directory').
     */
    public function getType(): string
    {
        return 'create_directory';
    }
}
