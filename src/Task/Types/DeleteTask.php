<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

final class DeleteTask extends BaseTask
{
    protected function runTask(bool $debug, bool $silent): bool
    {
        $path = (string) ($this->params['path'] ?? '');
        if ($path === '') {
            throw new \InvalidArgumentException("Missing 'path' parameter.");
        }
        if (!file_exists($path) && !is_link($path)) {
            $this->logger->debug(sprintf("Path '%s' does not exist.", $path));
            return true;
        }
        // Unlink the link itself; never recurse through its target.
        return is_link($path) || !is_dir($path) ? $this->deleteFile($path) : $this->deleteDirectory($path);
    }

    private function deleteFile(string $path): bool
    {
        if (!@unlink($path)) {
            $this->logger->error(sprintf("Failed to delete '%s'.", $path));
            return false;
        }
        $this->logger->debug(sprintf("Deleted file '%s'.", $path));
        return true;
    }

    private function deleteDirectory(string $dir): bool
    {
        if (is_link($dir)) {
            return $this->deleteFile($dir);
        }
        $items = @scandir($dir);
        if ($items === false) {
            $this->logger->error(sprintf("Unable to read directory '%s'.", $dir));
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $ok = is_link($path) || !is_dir($path) ? $this->deleteFile($path) : $this->deleteDirectory($path);
            if (!$ok) {
                return false;
            }
        }
        if (!@rmdir($dir)) {
            $this->logger->error(sprintf("Failed to remove directory '%s'.", $dir));
            return false;
        }
        $this->logger->debug(sprintf("Deleted directory '%s'.", $dir));
        return true;
    }

    protected function validateParams(): void
    {
        if (!isset($this->params['path']) || !is_string($this->params['path'])) {
            throw new \InvalidArgumentException("Delete task requires a string 'path' parameter.");
        }
        $this->assertSafePath($this->params['path'], 'delete path');
    }

    #[\Override]
    public function getType(): string
    {
        return 'delete';
    }
}
