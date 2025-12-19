<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

/**
 * DeleteTask Class
 *
 * Implements a task for deleting files and directories within PHPMake tool.
 * Ensures proper handling of file and directory deletion while managing errors.
 *
 * @package    PHPMake
 * @subpackage Task
 */
final class DeleteTask extends BaseTask
{
    /**
     * Execute delete task.
     *
     * Determines if target is a file or directory and performs the appropriate delete operation.
     *
     * @param bool $debug  Enable debug mode for detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of delete operation (true if successful, false otherwise).
     *
     * @throws \Exception If required parameters are missing.
     */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $path = $this->params['path'] ?? '';

        if (empty($path)) {
            throw new \Exception("Missing 'path' parameter.");
        }

        if (!file_exists($path)) {
            $this->logger->debug(sprintf("Path '%s' does not exist.", $path));
            return true;
        }

        return is_dir($path) ? $this->deleteDirectory($path) : $this->deleteFile($path);
    }

    /**
     * Delete a single file.
     *
     * Removes specified file from the system and logs operation.
     *
     * @param string $path File path to be deleted.
     *
     * @return bool Status of file deletion.
     */
    private function deleteFile(string $path): bool
    {
        if (unlink($path)) {
            $this->logger->debug(sprintf("Deleted file '%s'.", $path));
            return true;
        }

        return false;
    }

    /**
     * Delete an entire directory recursively.
     *
     * Removes directory and all of its contents.
     *
     * @param string $dir Directory path to be deleted.
     *
     * @return bool Status of directory deletion operation.
     */
    private function deleteDirectory(string $dir): bool
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirectory($path) : $this->deleteFile($path);
        }

        if (rmdir($dir)) {
            $this->logger->debug(sprintf("Deleted directory '%s'.", $dir));
            return true;
        }

        return false;
    }

    /**
     * Validate required task parameters.
     *
     * @throws \Exception On any issue.
     */
    protected function validateParams(): void
    {
        if (!isset($this->params['path'])) {
            throw new \Exception("Missing 'path' parameter for delete task.");
        }

        $path = trim($this->params['path']);
        $absolutePath = realpath($path) ?: $path;

        // Critical safety checks.
        $blacklisted = ['', '/', '.', '..', DIRECTORY_SEPARATOR];

        if (in_array($path, $blacklisted) || in_array($absolutePath, $blacklisted)) {
            throw new \Exception(sprintf("Safety Violation: Attempted to delete a protected or root directory: '%s'", $path));
        }
    }

    /**
     * Get task type identifier.
     *
     * Returns string representation of task type.
     *
     * @return string The task type ('delete').
     */
    #[\Override]
    public function getType(): string
    {
        return 'delete';
    }
}
