<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;

    /** Deletes a file or directory tree without following symbolic links. */
final class DeleteTask extends BaseTask
{
    /** Deletes the configured path or reports success when it is absent. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $path = (string) ($this->params['path'] ?? '');
        if ($path === '') {
            throw new \InvalidArgumentException("Missing 'path' parameter.");
        }
        if (!file_exists($path) && !$this->isSymbolicLinkOrJunction($path)) {
            $this->logger->debug(sprintf("Path '%s' does not exist.", $path));
            return true;
        }
        // Unlink the link itself; never recurse through its target.
        return $this->isSymbolicLinkOrJunction($path) ? $this->removeLink($path) : (!is_dir($path) ? $this->deleteFile($path) : $this->deleteDirectory($path));
    }

    /** Unlinks a file or symbolic link and reports failures. */
    private function deleteFile(string $path): bool
    {
        if (!@unlink($path)) {
            $this->logger->error(sprintf("Failed to delete '%s'.", $path));
            return false;
        }
        $this->logger->debug(sprintf("Deleted file '%s'.", $path));
        return true;
    }

    /** Removes a symbolic link or junction without touching its target. */
    private function removeLink(string $path): bool
    {
        if (!$this->removeSymbolicLinkOrJunction($path)) {
            $this->logger->error(sprintf("Failed to delete link '%s'.", $path));
            return false;
        }
        return true;
    }

    /** Recursively deletes a directory and propagates child failures. */
    private function deleteDirectory(string $dir): bool
    {
        if ($this->isSymbolicLinkOrJunction($dir)) {
            return $this->removeLink($dir);
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
            $ok = $this->isSymbolicLinkOrJunction($path) ? $this->removeLink($path) : (!is_dir($path) ? $this->deleteFile($path) : $this->deleteDirectory($path));
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

    /** Validates the deletion path while preserving safe symlink-unlink behavior. */
    protected function validateParams(): void
    {
        if (!isset($this->params['path']) || !is_string($this->params['path'])) {
            throw new \InvalidArgumentException("Delete task requires a string 'path' parameter.");
        }
        $path = $this->params['path'];

        // Deleting a symlink removes the link, not its target. Validate its
        // parent directory instead of resolving the final symlink target.
        if ($this->isSymbolicLinkOrJunction($path)) {
            $parent = dirname($path);
            if ($parent === '.') {
                $parent = getcwd();
            }
            $this->assertSafePath($parent, 'delete path parent');
            return;
        }

        $this->assertSafePath($path, 'delete path');
    }

    #[\Override]
    /** Returns the delete task identifier. */
    public function getType(): string
    {
        return 'delete';
    }
}
