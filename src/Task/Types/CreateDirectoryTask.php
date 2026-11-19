<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

/** Creates directories while enforcing PHPMake project-path boundaries. */
final class CreateDirectoryTask extends BaseTask
{
    /** Creates the configured directory, treating an existing directory as success. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $dirName = $this->params['name'] ?? '';
        if ($dirName === '') {
            throw new \InvalidArgumentException("Missing 'name' parameter.");
        }
        $this->assertSafePath($dirName, 'create_directory name');

        if (is_dir($dirName)) {
            $this->logger->debug(sprintf("Directory '%s' already exists.", $dirName));
            return true;
        }

        error_clear_last();
        if (@mkdir($dirName, 0o777, true) || is_dir($dirName)) {
            $this->logger->debug(sprintf("Created directory '%s'.", $dirName));
            return true;
        }

        $error = error_get_last();
        $reason = $error['message'] ?? 'unknown filesystem error';
        $this->logger->error(sprintf("Unable to create directory '%s': %s", $dirName, $reason));
        return false;
    }

    /** Validates the directory path and ensures it stays within the project. */
    protected function validateParams(): void
    {
        if (!isset($this->params['name']) || !is_string($this->params['name']) || trim($this->params['name']) === '') {
            throw new \InvalidArgumentException("Missing or invalid 'name' parameter for create_directory task.");
        }
        $this->assertSafePath($this->params['name'], 'create_directory name');
    }

    /** Returns the create_directory task identifier. */
    #[\Override]
    public function getType(): string
    {
        return 'create_directory';
    }
}
