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
        $src = $this->params['src'] ?? '';
        $dest = $this->params['dest'] ?? '';

        if (empty($src) || empty($dest)) {
            throw new \Exception("Missing 'src' or 'dest' parameter.");
        }

        if (!file_exists($src)) {
            $this->logger->debug("Source '{$src}' does not exist. Skipping copy.");
            return true;
        }

        return is_dir($src) ? $this->copyDirectory($src, $dest) : $this->copyFile($src, $dest);
    }

    /**
     * Copy a single file.
     *
     * Performs a file copy operation and logs success.
     *
     * @param string $src  Source file path.
     * @param string $dest Destination file path.
     *
     * @return bool Status of the file copy operation.
     */
    private function copyFile(string $src, string $dest): bool
    {
        if (copy($src, $dest)) {
            $this->logger->debug("Copied file '{$src}' → '{$dest}'.");
            return true;
        }

        return false;
    }

    /**
     * Copy an entire directory recursively.
     *
     * Create destination directory if it does not exist and copies all files recursively.
     *
     * @param string $src  Source directory path.
     * @param string $dest Destination directory path.
     *
     * @return bool Status of directory copy operation.
     */
    private function copyDirectory(string $src, string $dest): bool
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0777, true);
        }

        foreach (scandir($src) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $s = $src . DIRECTORY_SEPARATOR . $item;
            $d = $dest . DIRECTORY_SEPARATOR . $item;
            is_dir($s) ? $this->copyDirectory($s, $d) : $this->copyFile($s, $d);
        }

        $this->logger->debug("Copied directory '{$src}' → '{$dest}'.");
        return true;
    }

    /**
     * Get task type identifier.
     *
     * Returns string representation of task type.
     *
     * @return string The task type ('copy').
     */
    public function getType(): string
    {
        return 'copy';
    }

    /**
     * Validate required task parameters.
     *
     * Ensures presence of 'src' and 'dest' parameters before execution.
     *
     * @throws \Exception If required parameters are missing.
     */
    protected function validateParams()
    {
        if (!isset($this->params['src']) || !isset($this->params['dest'])) {
            throw new \Exception("Missing 'src' or 'dest' parameter for copy task.");
        }
    }
}
