<?php

namespace PhpMake\Task\Types;

use PhpMake\Filesystem\AtomicDirectoryPublisher;
use PhpMake\Filesystem\AtomicFilePublisher;
use PhpMake\Filesystem\FilesystemIdentity;
use PhpMake\Filesystem\FilesystemTree;
use PhpMake\Task\BaseTask;

/** Copies files and directory trees without following symbolic links. */
final class CopyTask extends BaseTask
{
    /** Copies the configured source to its destination. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $source = (string) ($this->params['source'] ?? '');
        $dest = (string) ($this->params['dest'] ?? '');
        if ($source === '' || $dest === '') {
            throw new \InvalidArgumentException("Missing 'source' or 'dest' parameter.");
        }

        $this->assertSafePath($source, 'copy source');
        $this->assertSafePath($dest, 'copy dest', false);
        if ($this->isSymbolicLinkOrJunction($source) || !file_exists($source)) {
            $this->logger->error(sprintf("Copy source '%s' does not exist or is a symlink.", $source));
            return false;
        }

        if (!is_dir($source)) {
            if (!is_file($source)) {
                $this->logger->error(sprintf("Copy source '%s' is not a regular file or directory.", $source));
                return false;
            }

            return $this->copyFile($source, $dest);
        }

        return $this->copyDirectoryTransactionally($source, $dest);
    }

    /** Copies one file through a same-directory staging file before publication. */
    private function copyFile(string $source, string $dest): bool
    {
        $this->assertSafePath($source, 'copy source');
        $this->assertSafePath($dest, 'copy dest', false);
        if ($this->isSymbolicLinkOrJunction($dest)) {
            $this->logger->error(sprintf("Refusing to write through destination link '%s'.", $dest));
            return false;
        }

        if ($this->isSymbolicLinkOrJunction($source)) {
            $this->logger->error(sprintf("Refusing to copy symlink '%s'.", $source));
            return false;
        }

        if (FilesystemIdentity::sameFile($source, $dest)) {
            $this->logger->error(sprintf("Refusing to copy '%s' onto the same filesystem entry '%s'.", $source, $dest));
            return false;
        }

        if (is_dir($dest)) {
            $this->logger->error(sprintf("File copy destination '%s' is a directory.", $dest));
            return false;
        }

        $destDir = dirname($dest);
        if (!is_dir($destDir) && !@mkdir($destDir, 0777, true) && !is_dir($destDir)) {
            $this->logger->error(sprintf("Unable to create destination directory '%s'.", $destDir));
            return false;
        }

        $resolvedDestDir = realpath($destDir);
        if ($resolvedDestDir === false) {
            $this->logger->error(sprintf("Unable to resolve destination directory '%s'.", $destDir));
            return false;
        }

        $this->assertSafePath($resolvedDestDir, 'copy destination directory');
        $this->assertSafePath($dest, 'copy dest', false);
        if ($this->isSymbolicLinkOrJunction($dest)) {
            $this->logger->error(sprintf("Refusing to write through destination link '%s'.", $dest));
            return false;
        }

        $destPath = realpath($dest) ?: $resolvedDestDir . DIRECTORY_SEPARATOR . basename($dest);
        $this->assertSafePath($destPath, 'copy dest', false);

        // Staging in the destination directory keeps publication on one volume.
        $temporary = @tempnam($resolvedDestDir, '.phpmake-copy-');
        if ($temporary === false || dirname($temporary) !== $resolvedDestDir) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }

            $this->logger->error(sprintf("Unable to create temporary copy beside '%s'.", $destPath));
            return false;
        }

        if (!@copy($source, $temporary)) {
            @unlink($temporary);
            $this->logger->error(sprintf("Failed to stage copy from '%s' to '%s'.", $source, $destPath));
            return false;
        }

        $sourcePermissions = @fileperms($source);
        if ($sourcePermissions !== false) {
            @chmod($temporary, $sourcePermissions & 0777);
        }

        try {
            AtomicFilePublisher::publish($temporary, $destPath);
        } catch (\Throwable $throwable) {
            $this->logger->error($throwable->getMessage());
            return false;
        }

        $this->logger->debug(sprintf("Copied file '%s' → '%s'.", $source, $destPath));
        return true;
    }


    /** Copies a directory tree without following links or unsupported entries. */
    private function copyDirectory(string $source, string $dest): bool
    {
        $this->assertSafePath($source, 'copy source');
        $this->assertSafePath($dest, 'copy destination', false);

        if (!FilesystemTree::copyDirectory($source, $dest)) {
            $this->logger->error(sprintf("Unable to safely copy directory '%s' to '%s'.", $source, $dest));
            return false;
        }

        $this->logger->debug(sprintf("Copied directory '%s' → '%s'.", $source, $dest));
        return true;
    }

    /** Copies into a staging tree and publishes it with rollback on failure. */
    private function copyDirectoryTransactionally(string $source, string $destination): bool
    {
        if ($this->destinationIsWithinSource($source, $destination)) {
            $this->logger->error(sprintf(
                "Refusing to copy directory '%s' into itself or one of its descendants.",
                $source
            ));
            return false;
        }

        if ($this->isSymbolicLinkOrJunction($destination)) {
            $this->logger->error(sprintf("Refusing to replace destination link '%s'.", $destination));
            return false;
        }

        if (file_exists($destination) && !is_dir($destination)) {
            $this->logger->error(sprintf("Directory copy destination '%s' is not a directory.", $destination));
            return false;
        }

        $staging = rtrim($destination, DIRECTORY_SEPARATOR)
            . '.phpmake-' . bin2hex(random_bytes(8)) . '.staging';

        if (is_dir($destination) && !$this->copyDirectory($destination, $staging)) {
            if (!FilesystemTree::remove($staging)) {
                $this->logger->error(sprintf("Unable to remove incomplete staging tree '%s'.", $staging));
            }

            return false;
        }

        if (!$this->copyDirectory($source, $staging)) {
            if (!FilesystemTree::remove($staging)) {
                $this->logger->error(sprintf("Unable to remove incomplete staging tree '%s'.", $staging));
            }

            return false;
        }

        try {
            AtomicDirectoryPublisher::publish($staging, $destination);
        } catch (\Throwable $throwable) {
            $this->logger->error($throwable->getMessage());
            if (!FilesystemTree::remove($staging)) {
                $this->logger->error(sprintf("Unable to remove incomplete staging tree '%s'.", $staging));
            }

            return false;
        }

        return true;
    }

    /** Rejects destination paths that resolve to the source or one of its descendants. */
    private function destinationIsWithinSource(string $source, string $destination): bool
    {
        $sourceReal = realpath($source);
        $destinationReal = $this->resolvePathForComparison($destination);
        if ($sourceReal === false || $destinationReal === null) {
            // Refuse uncertain paths rather than risk an unbounded recursive copy.
            return true;
        }

        $sourcePath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceReal), DIRECTORY_SEPARATOR);
        $destinationPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $destinationReal), DIRECTORY_SEPARATOR);
        // Both paths have canonical existing prefixes. Preserve their case:
        // case-insensitive comparison is wrong for case-sensitive Windows volumes.
        return $destinationPath === $sourcePath
            || str_starts_with($destinationPath, $sourcePath . DIRECTORY_SEPARATOR);
    }

    /** Resolves a destination using the same symlink-aware rules as task validation. */
    private function resolvePathForComparison(string $path): ?string
    {
        try {
            return $this->resolvePathInFilesystemOrder($path, 'copy destination comparison');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Returns the copy task identifier. */
    public function getType(): string
    {
        return 'copy';
    }

    /** Validates the source and destination paths. */
    protected function validateParams(): void
    {
        if (
            !isset($this->params['source'], $this->params['dest'])
            || !is_string($this->params['source'])
            || !is_string($this->params['dest'])
        ) {
            throw new \InvalidArgumentException("Copy task requires string 'source' and 'dest' parameters.");
        }

        $this->assertSafePath($this->params['source'], 'copy source');
        $this->assertSafePath($this->params['dest'], 'copy dest', false);
    }
}
