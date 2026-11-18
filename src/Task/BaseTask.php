<?php

namespace PhpMake\Task;

use PhpMake\Utilities\Logger;

    /** Provides shared execution, logging, and filesystem-safety behavior for tasks. */
abstract class BaseTask implements TaskInterface
{
    /** Initializes the task and validates its parameters. */
    public function __construct(protected array $params, protected Logger $logger)
    {
        $this->validateParams();
    }

    /** Runs the task and converts execution exceptions into a failed result. */
    public function execute(bool $debug, bool $silent): bool
    {
        try {
            return $this->runTask($debug, $silent);
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage());
            return false;
        }
    }

    /** Returns the task type identifier. */
    public function getType(): string
    {
        return static::class;
    }

    /** Executes the task-specific operation. */
    abstract protected function runTask(bool $debug, bool $silent): bool;
    /** Validates task-specific parameters before execution. */
    abstract protected function validateParams(): void;

    /** Detects POSIX symbolic links and Windows NTFS junctions. */
    protected function isSymbolicLinkOrJunction(string $path): bool
    {
        clearstatcache(true, $path);
        if (PHP_OS_FAMILY !== 'Windows') {
            return is_link($path);
        }

        // Check is_dir() before is_link() to preserve Windows reparse-point
        // metadata for lstat(), matching PHP's junction detection semantics.
        if (!is_dir($path)) {
            return is_link($path);
        }
        if (is_link($path)) {
            return true;
        }

        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] & 0xF000) !== 0x4000);
    }

    /** Removes a link itself without traversing its target. */
    protected function removeSymbolicLinkOrJunction(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows' && @rmdir($path)) {
            return true;
        }

        return @unlink($path);
    }

    /**
     * Validate lexical containment and resolve every existing path prefix.
     * This rejects symlinked ancestors that redirect operations outside CWD.
     */
    protected function assertSafePath(string $path, string $label, bool $allowProjectRoot = true): void
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || in_array($path, ['/', '\\', '.', '..'], true)) {
            throw new \InvalidArgumentException(sprintf("Safety Violation: Invalid or protected path for %s.", $label));
        }

        $cwd = realpath(getcwd());
        if ($cwd === false) {
            throw new \RuntimeException('Unable to resolve current working directory.');
        }

        $candidate = $this->isAbsolutePath($path) ? $path : $cwd . DIRECTORY_SEPARATOR . $path;
        $candidate = $this->normalizePath($candidate);
        $this->assertWithinDirectory($candidate, $cwd, $label);
        if (!$allowProjectRoot && $this->pathsEqual($candidate, $cwd)) {
            throw new \InvalidArgumentException(sprintf("Safety Violation: Refusing to operate on the project root for %s.", $label));
        }

        // Resolve the deepest existing prefix. realpath() follows symlinks,
        // exposing symlink escapes even when the final path does not exist.
        $probe = $candidate;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        $resolved = realpath($probe);
        if ($resolved === false) {
            // A dangling symlink is not safe to traverse.
            if (is_link($probe)) {
                throw new \InvalidArgumentException(sprintf("Safety Violation: Dangling symlink in path for %s.", $label));
            }
            return;
        }

        $this->assertWithinDirectory($this->normalizePath($resolved), $this->normalizePath($cwd), $label);
    }

    /** Rejects paths that are not contained within the allowed directory. */
    private function assertWithinDirectory(string $path, string $cwd, string $label): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $cwd = rtrim($cwd, DIRECTORY_SEPARATOR);
        $matches = PHP_OS_FAMILY === 'Windows' ? strncasecmp($path, $cwd . DIRECTORY_SEPARATOR, strlen($cwd . DIRECTORY_SEPARATOR)) === 0 : str_starts_with($path, $cwd . DIRECTORY_SEPARATOR);
        if (!$this->pathsEqual($path, $cwd) && !$matches) {
            throw new \InvalidArgumentException(sprintf("Safety Violation: Path for %s escapes project directory: '%s'.", $label, $path));
        }
    }

    /** Compares normalized paths using the host filesystem's case semantics. */
    private function pathsEqual(string $left, string $right): bool
    {
        $left = rtrim($this->normalizePath($left), DIRECTORY_SEPARATOR);
        $right = rtrim($this->normalizePath($right), DIRECTORY_SEPARATOR);
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    /** Determines whether a path uses an absolute-path format. */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\')
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'));
    }

    /** Normalizes path separators and resolves lexical dot segments. */
    private function normalizePath(string $path): string
    {
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $prefix = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
    }
}
