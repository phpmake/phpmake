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
        if ($this->isSymbolicLinkOrJunction($source) || !file_exists($source)) {
            $this->logger->error(sprintf("Copy source '%s' does not exist or is a symlink.", $source));
            return false;
        }

        return is_dir($source)
            ? $this->copyDirectory($source, $dest)
            : $this->copyFile($source, $dest);
    }

    /** Copies one file after ensuring its destination directory exists. */
    private function copyFile(string $source, string $dest): bool
    {
        if ($this->isSymbolicLinkOrJunction($source)) {
            $this->logger->error(sprintf("Refusing to copy symlink '%s'.", $source));
            return false;
        }

        $destDir = dirname($dest);
        if (!is_dir($destDir) && !@mkdir($destDir, 0o777, true) && !is_dir($destDir)) {
            $this->logger->error(sprintf("Unable to create destination directory '%s'.", $destDir));
            return false;
        }
        if (!@copy($source, $dest)) {
            $this->logger->error(sprintf("Failed to copy '%s' to '%s'.", $source, $dest));
            return false;
        }

        $this->logger->debug(sprintf("Copied file '%s' → '%s'.", $source, $dest));
        return true;
    }

    /** Recursively copies a directory and stops on the first failure. */
    private function copyDirectory(string $source, string $dest): bool
    {
        if ($this->isSymbolicLinkOrJunction($source)) {
            $this->logger->error(sprintf("Refusing to traverse symlink directory '%s'.", $source));
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
        if (PHP_OS_FAMILY === 'Windows') {
            $sourcePath = strtolower($sourcePath);
            $destinationPath = strtolower($destinationPath);
        }

        return $destinationPath === $sourcePath
            || str_starts_with($destinationPath, $sourcePath . DIRECTORY_SEPARATOR);
    }

    /** Resolves existing path prefixes and appends any missing destination segments. */
    private function resolvePathForComparison(string $path): ?string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (
            !str_starts_with($path, DIRECTORY_SEPARATOR)
            && !(PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:\\\\/', $path) === 1)
        ) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            return DIRECTORY_SEPARATOR;
        }

        $missingSegments = [];
        $probe = $path;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return null;
            }
            array_unshift($missingSegments, basename($probe));
            $probe = $parent;
        }

        $resolved = realpath($probe);
        if ($resolved === false) {
            return null;
        }

        foreach ($missingSegments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $resolved = dirname($resolved);
                continue;
            }
            $resolved .= DIRECTORY_SEPARATOR . $segment;
        }

        return $resolved;
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
        $this->assertSafePath($this->params['dest'], 'copy dest');
    }
}