<?php

namespace PhpMake\Task;

use PhpMake\Utilities\Logger;

abstract class BaseTask implements TaskInterface
{
    public function __construct(protected array $params, protected Logger $logger)
    {
        $this->validateParams();
    }

    public function execute(bool $debug, bool $silent): bool
    {
        try {
            return $this->runTask($debug, $silent);
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage());
            return false;
        }
    }

    public function getType(): string
    {
        return static::class;
    }

    abstract protected function runTask(bool $debug, bool $silent): bool;
    abstract protected function validateParams();

    /**
     * Validate lexical containment and resolve every existing path prefix.
     * This rejects symlinked ancestors that redirect operations outside CWD.
     */
    protected function assertSafePath(string $path, string $label): void
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

    private function assertWithinDirectory(string $path, string $cwd, string $label): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $cwd = rtrim($cwd, DIRECTORY_SEPARATOR);
        if ($path !== $cwd && !str_starts_with($path, $cwd . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException(sprintf("Safety Violation: Path for %s escapes project directory: '%s'.", $label, $path));
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\')
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'));
    }

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
