<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

/**
 * CopyTask Class
 *
 * Implements a task for copying files and directories within PHPMake tool.
 * It ensures files and directories are copied efficiently while handling errors.
 *
 * @package    PHPMake
 * @subpackage Task
 */
final class CopyTask extends BaseTask
{
    /**
     * Execute copy task.
     *
     * Determines if source is a file or directory and performs the appropriate copy operation.
     *
     * @param bool $debug  Enable debug mode for detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of copy operation (true if successful, false otherwise).
     *
     * @throws \Exception If required parameters are missing.
     */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $source = $this->params['source'] ?? '';
        $dest = $this->params['dest'] ?? '';

        if (empty($source) || empty($dest)) {
            throw new \Exception("Missing 'source' or 'dest' parameter.");
        }

        if (!file_exists($source)) {
            $this->logger->debug(sprintf("Source '%s' does not exist. Skipping copy.", $source));
            return true;
        }

        return is_dir($source) ? $this->copyDirectory($source, $dest) : $this->copyFile($source, $dest);
    }

    /**
     * Copy a single file.
     *
     * Performs a file copy operation and logs success.
     *
     * @param string $source  Source file path.
     * @param string $dest Destination file path.
     *
     * @return bool Status of the file copy operation.
     */
    private function copyFile(string $source, string $dest): bool
    {
        if (copy($source, $dest)) {
            $this->logger->debug(sprintf("Copied file '%s' → '%s'.", $source, $dest));
            return true;
        }

        return false;
    }

    /**
     * Copy an entire directory recursively.
     *
     * Create destination directory if it does not exist and copies all files recursively.
     *
     * @param string $source  Source directory path.
     * @param string $dest Destination directory path.
     *
     * @return bool Status of directory copy operation.
     */
    private function copyDirectory(string $source, string $dest): bool
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0o777, true);
        }

        foreach (scandir($source) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $s = $source . DIRECTORY_SEPARATOR . $item;
            $d = $dest . DIRECTORY_SEPARATOR . $item;
            is_dir($s) ? $this->copyDirectory($s, $d) : $this->copyFile($s, $d);
        }

        $this->logger->debug(sprintf("Copied directory '%s' → '%s'.", $source, $dest));
        return true;
    }

    /**
     * Get task type identifier.
     *
     * Returns string representation of task type.
     *
     * @return string The task type ('copy').
     */
    #[\Override]
    public function getType(): string
    {
        return 'copy';
    }

    /**
     * Validate required task parameters.
     *
     * Ensures presence of 'source' and 'dest' parameters before execution.
     *
     * @throws \Exception If required parameters are missing.
     */
    protected function validateParams()
    {
        if (!isset($this->params['source']) || !isset($this->params['dest'])) {
            throw new \Exception("Missing 'source' or 'dest' parameter for copy task.");
        }
    }
}
