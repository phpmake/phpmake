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

        if (
            PHP_OS_FAMILY === 'Windows'
            && strlen($path) >= 2
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && (strlen($path) === 2 || ($path[2] !== '\\' && $path[2] !== '/'))
        ) {
            throw new \InvalidArgumentException(sprintf(
                "Safety Violation: Drive-relative paths are not allowed for %s.",
                $label
            ));
        }


        $cwd = realpath(getcwd());
        if ($cwd === false) {
            throw new \RuntimeException('Unable to resolve current working directory.');
        }

        $candidate = $this->isAbsolutePath($path) ? $path : $cwd . DIRECTORY_SEPARATOR . $path;

        // Resolve each component in filesystem order. Resolve a symlink before
        // applying a subsequent ".."; lexical normalization first is unsafe.
        $candidate = $this->resolvePathInFilesystemOrder($candidate, $label);
        $this->assertWithinDirectory($candidate, $cwd, $label);
        if (!$allowProjectRoot && $this->pathsEqual($candidate, $cwd)) {
            throw new \InvalidArgumentException(sprintf("Safety Violation: Refusing to operate on the project root for %s.", $label));
        }
    }

    /**
     * Resolves path components in operating-system order, following links
     * before applying subsequent parent-directory components.
     */
    protected function resolvePathInFilesystemOrder(string $path, string $label): string
    {
        if (!$this->isAbsolutePath($path)) {
            $cwd = realpath(getcwd());
            if ($cwd === false) {
                throw new \RuntimeException('Unable to resolve current working directory.');
            }
            $path = $cwd . DIRECTORY_SEPARATOR . $path;
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $root = DIRECTORY_SEPARATOR;
        $remaining = ltrim($path, DIRECTORY_SEPARATOR);

        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            $root = substr($path, 0, 3);
            $remaining = substr($path, 3);
        } elseif (PHP_OS_FAMILY === 'Windows' && str_starts_with($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
            $parts = explode(DIRECTORY_SEPARATOR, ltrim($path, DIRECTORY_SEPARATOR), 3);
            if (count($parts) < 2) {
                throw new \InvalidArgumentException(sprintf("Safety Violation: Invalid UNC path for %s.", $label));
            }
            $root = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . $parts[0] . DIRECTORY_SEPARATOR . $parts[1] . DIRECTORY_SEPARATOR;
            $remaining = $parts[2] ?? '';
        }

        $current = rtrim($root, DIRECTORY_SEPARATOR);
        if ($current === '' || preg_match('/^[A-Za-z]:$/', $current) === 1) {
            $current .= DIRECTORY_SEPARATOR;
        }

        foreach (explode(DIRECTORY_SEPARATOR, $remaining) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }

            if ($component === '..') {
                if (!file_exists($current) && !$this->isSymbolicLinkOrJunction($current)) {
                    throw new \InvalidArgumentException(sprintf("Safety Violation: Unresolved path component before '..' for %s.", $label));
                }
                if (!is_dir($current)) {
                    throw new \InvalidArgumentException(sprintf("Safety Violation: Non-directory path component for %s.", $label));
                }
                $rootBoundary = rtrim($root, DIRECTORY_SEPARATOR);
                $currentBoundary = rtrim($current, DIRECTORY_SEPARATOR);
                if ($currentBoundary !== $rootBoundary) {
                    $current = dirname($currentBoundary);
                    if ($current === '') {
                        $current = DIRECTORY_SEPARATOR;
                    }
                }
                continue;
            }

            $candidate = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $component;
            if (file_exists($candidate) || $this->isSymbolicLinkOrJunction($candidate)) {
                $resolved = realpath($candidate);
                if ($resolved === false) {
                    throw new \InvalidArgumentException(sprintf("Safety Violation: Dangling or unresolvable link in path for %s.", $label));
                }
                $current = $resolved;
            } else {
                $current = $candidate;
            }
        }

        return $this->normalizePath($current);
    }

    /** Rejects paths that are not contained within the allowed directory. */
    private function assertWithinDirectory(string $path, string $cwd, string $label): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $cwd = rtrim($cwd, DIRECTORY_SEPARATOR);
        $prefix = ($cwd === '' ? DIRECTORY_SEPARATOR : $cwd . DIRECTORY_SEPARATOR);
        // Existing components have already been canonicalized by realpath().
        // A case-sensitive comparison is therefore correct on both sensitive
        // and insensitive Windows/macOS volumes.
        $matches = str_starts_with($path, $prefix);
        if (!$this->pathsEqual($path, $cwd) && !$matches) {
            throw new \InvalidArgumentException(sprintf("Safety Violation: Path for %s escapes project directory: '%s'.", $label, $path));
        }
    }

    /** Compares normalized paths using the host filesystem's case semantics. */
    private function pathsEqual(string $left, string $right): bool
    {
        clearstatcache(true, $left);
        clearstatcache(true, $right);
        $leftStat = @stat($left);
        $rightStat = @stat($right);
        if (is_array($leftStat) && is_array($rightStat)) {
            // Device/inode identity follows the actual mounted volume semantics.
            return $leftStat['dev'] === $rightStat['dev'] && $leftStat['ino'] === $rightStat['ino'];
        }

        $leftReal = realpath($left);
        $rightReal = realpath($right);
        if ($leftReal !== false && $rightReal !== false) {
            return $leftReal === $rightReal;
        }

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
        $isUnc = PHP_OS_FAMILY === 'Windows'
            && str_starts_with($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
        $prefix = $isUnc
            ? DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR
            : (str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '');
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
