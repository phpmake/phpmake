<?php

namespace PhpMake\Task\Types;

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

        if ($this->pathsReferToSameFile($source, $dest)) {
            $this->logger->error(sprintf("Refusing to copy '%s' onto the same filesystem entry '%s'.", $source, $dest));
            return false;
        }

        if (is_dir($dest)) {
            $this->logger->error(sprintf("File copy destination '%s' is a directory.", $dest));
            return false;
        }

        $destDir = dirname($dest);
        if (!is_dir($destDir) && !@mkdir($destDir, 0o777, true) && !is_dir($destDir)) {
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
            @chmod($temporary, $sourcePermissions & 0o777);
        }

        if (!file_exists($destPath)) {
            if (!@rename($temporary, $destPath)) {
                @unlink($temporary);
                $this->logger->error(sprintf("Unable to publish copied file '%s'.", $destPath));
                return false;
            }
        } else {
            $backup = @tempnam($resolvedDestDir, '.phpmake-copy-backup-');
            if ($backup === false || dirname($backup) !== $resolvedDestDir) {
                if (is_string($backup)) {
                    @unlink($backup);
                }

                @unlink($temporary);
                $this->logger->error(sprintf("Unable to create recovery path beside '%s'.", $destPath));
                return false;
            }

            if (!@unlink($backup)) {
                @unlink($temporary);
                $this->logger->error(sprintf("Unable to prepare recovery path '%s'.", $backup));
                return false;
            }

            if (!@rename($destPath, $backup)) {
                @unlink($temporary);
                $this->logger->error(sprintf("Unable to preserve existing destination '%s'.", $destPath));
                return false;
            }

            if (!@rename($temporary, $destPath)) {
                if (!@rename($backup, $destPath)) {
                    if (!@unlink($temporary)) {
                        $this->logger->error(sprintf("Unable to remove incomplete staged copy '%s'.", $temporary));
                    }

                    $this->logger->error(sprintf(
                        "CRITICAL: File copy publication and rollback failed. The original file is preserved at '%s'; restore it manually to '%s'.",
                        $backup,
                        $destPath
                    ));
                    return false;
                }

                @unlink($temporary);
                $this->logger->error(sprintf("Unable to publish copied file '%s'; the original was restored.", $destPath));
                return false;
            }

            if (!@unlink($backup)) {
                $this->logger->error(sprintf(
                    "File copied to '%s', but the previous destination could not be removed. Recovery backup retained at '%s'.",
                    $destPath,
                    $backup
                ));
                return false;
            }
        }

        $this->logger->debug(sprintf("Copied file '%s' → '%s'.", $source, $destPath));
        return true;
    }

    /** Compares existing filesystem entries by device and inode. */
    private function pathsReferToSameFile(string $left, string $right): bool
    {
        clearstatcache(true, $left);
        clearstatcache(true, $right);
        $leftStat = @stat($left);
        $rightStat = @stat($right);
        return is_array($leftStat)
            && is_array($rightStat)
            && $leftStat['dev'] === $rightStat['dev']
            && $leftStat['ino'] === $rightStat['ino'];
    }

    /** Recursively copies a directory and stops on the first failure. */
    private function copyDirectory(string $source, string $dest): bool
    {
        $this->assertSafePath($source, 'copy source');
        $this->assertSafePath($dest, 'copy destination', false);
        if ($this->isSymbolicLinkOrJunction($source)) {
            $this->logger->error(sprintf("Refusing to traverse symlink directory '%s'.", $source));
            return false;
        }

        if ($this->isSymbolicLinkOrJunction($dest)) {
            $this->logger->error(sprintf("Refusing to copy through destination link '%s'.", $dest));
            return false;
        }

        if ($this->destinationIsWithinSource($source, $dest)) {
            $this->logger->error(sprintf(
                "Refusing to copy directory '%s' into itself or one of its descendants.",
                $source
            ));
            return false;
        }

        if (!is_dir($dest) && !@mkdir($dest, 0o777, true) && !is_dir($dest)) {
            $this->logger->error(sprintf("Unable to create destination directory '%s'.", $dest));
            return false;
        }

        $items = @scandir($source);
        if ($items === false) {
            $this->logger->error(sprintf("Unable to read source directory '%s'.", $source));
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $src = $source . DIRECTORY_SEPARATOR . $item;
            $target = $dest . DIRECTORY_SEPARATOR . $item;
            if ($this->isSymbolicLinkOrJunction($src)) {
                $this->logger->error(sprintf("Refusing to copy symlink '%s'.", $src));
                return false;
            }

            if (!is_dir($src) && !is_file($src)) {
                $this->logger->error(sprintf("Refusing to copy unsupported filesystem entry '%s'.", $src));
                return false;
            }

            $ok = is_dir($src)
                ? $this->copyDirectory($src, $target)
                : $this->copyFile($src, $target);
            if (!$ok) {
                return false;
            }
        }

        $this->logger->debug(sprintf("Copied directory '%s' → '%s'.", $source, $dest));
        return true;
    }

    /** Copies into a staging tree and swaps it into place only after success. */
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

        $base = rtrim($destination, DIRECTORY_SEPARATOR);
        $suffix = '.phpmake-' . bin2hex(random_bytes(8));
        $staging = $base . $suffix . '.staging';
        $backup = $base . $suffix . '.backup';

        if (is_dir($destination) && !$this->copyDirectory($destination, $staging)) {
            if (!$this->removeCreatedTree($staging)) {
                $this->logger->error(sprintf("Unable to remove incomplete staging tree '%s'.", $staging));
            }

            return false;
        }

        if (!$this->copyDirectory($source, $staging)) {
            if (!$this->removeCreatedTree($staging)) {
                $this->logger->error(sprintf("Unable to remove incomplete staging tree '%s'.", $staging));
            }

            return false;
        }

        $hadDestination = file_exists($destination);
        if ($hadDestination && !@rename($destination, $backup)) {
            $this->logger->error(sprintf("Unable to stage existing destination '%s'.", $destination));
            if (!$this->removeCreatedTree($staging)) {
                $this->logger->error(sprintf("Unable to remove incomplete staging tree '%s'.", $staging));
            }

            return false;
        }

        if (!@rename($staging, $destination)) {
            $this->logger->error(sprintf("Unable to publish copied directory '%s'.", $destination));
            if ($hadDestination && !@rename($backup, $destination)) {
                $this->logger->error(sprintf(
                    "CRITICAL: Copy rollback failed. The original destination is preserved at backup path '%s'; restore it manually to '%s'.",
                    $backup,
                    $destination
                ));
            }

            if (!$this->removeCreatedTree($staging)) {
                $this->logger->error(sprintf("Unable to remove incomplete staging tree '%s'.", $staging));
            }

            return false;
        }

        if ($hadDestination && !$this->removeCreatedTree($backup)) {
            $this->logger->error(sprintf(
                "Copy completed at '%s', but the previous destination could not be removed. Recovery backup retained at '%s'.",
                $destination,
                $backup
            ));
            return false;
        }

        return true;
    }

    /** Removes a partial tree and reports whether every filesystem operation succeeded. */
    private function removeCreatedTree(string $path): bool
    {
        if ($this->isSymbolicLinkOrJunction($path)) {
            return $this->removeSymbolicLinkOrJunction($path);
        }

        if (!is_dir($path)) {
            return !file_exists($path) || @unlink($path);
        }

        $items = @scandir($path);
        if ($items === false) {
            return false;
        }

        $success = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            if ($this->isSymbolicLinkOrJunction($child)) {
                $removed = $this->removeSymbolicLinkOrJunction($child);
            } elseif (!is_dir($child)) {
                $removed = !file_exists($child) || @unlink($child);
            } else {
                $removed = $this->removeCreatedTree($child);
            }

            if (!$removed) {
                return false;
            }
        }

        return @rmdir($path);
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

    #[\Override]
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
