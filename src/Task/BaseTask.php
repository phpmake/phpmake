<?php

namespace PhpMake\Task;

use PhpMake\Utilities\Logger;

/**
 * BaseTask Abstract Class
 *
 * Provides a foundational structure for task execution in PHPMake tool.
 * Implements the TaskInterface and ensures task validation and execution handling.
 *
 * @package    PHPMake
 * @subpackage Task
 */
abstract class BaseTask implements TaskInterface
{
    /**
     * Constructor
     *
     * Initializes task with provided parameters and a logger.
     *
     * @param array  $params Task parameters.
     * @param Logger $logger Logger instance.
     */
    public function __construct(protected array $params, protected Logger $logger)
    {
        $this->validateParams();
    }

    /**
     * Execute the task.
     *
     * Handles execution of task while managing errors and logging output.
     *
     * @param bool $debug  Debug flag to enable detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of task execution (true if successful, false otherwise).
     */
    public function execute(bool $debug, bool $silent): bool
    {
        try {
            return $this->runTask($debug, $silent);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            return false;
        }
    }

    /**
     * Get task type.
     *
     * Returns the class name of task.
     *
     * @return string The class name of the task.
     */
    public function getType(): string
    {
        return static::class;
    }

    /**
     * Execute task logic.
     *
     * Abstract method that must be implemented by child classes
     * to define specific task execution logic.
     *
     * @param bool $debug  Debug flag to enable detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of task execution.
     */
    abstract protected function runTask(bool $debug, bool $silent): bool;

    /**
     * Validate task parameters.
     *
     * Abstract method that performs validation on the task parameters to ensure correct execution.
     */
    abstract protected function validateParams();

    /**
     * Assert a path is safe and stays within the project (CWD) boundary.
     *
     * Rejects empty/root/parent references, null bytes, and any path that
     * resolves outside the current working directory. Works for paths that
     * do not exist yet (create/copy dest/archive output).
     *
     * @param string $path  Path from task params.
     * @param string $label Human-readable parameter name for error messages.
     *
     * @throws \Exception On safety violation.
     */
    protected function assertSafePath(string $path, string $label): void
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0")) {
            throw new \Exception(sprintf("Safety Violation: Invalid path for %s.", $label));
        }

        $dangerous = ['/', '\\', '.', '..', DIRECTORY_SEPARATOR];
        if (in_array($path, $dangerous, true)) {
            throw new \Exception(sprintf(
                "Safety Violation: Protected or root path not allowed for %s: '%s'",
                $label,
                $path
            ));
        }

        $cwd = realpath(getcwd());
        if ($cwd === false) {
            throw new \Exception('Unable to resolve current working directory.');
        }

        $cwd = $this->normalizePath($cwd);
        $absolute = $this->isAbsolutePath($path)
            ? $path
            : $cwd . DIRECTORY_SEPARATOR . $path;
        $normalized = $this->normalizePath($absolute);

        if ($normalized !== $cwd && !str_starts_with($normalized, $cwd . DIRECTORY_SEPARATOR)) {
            throw new \Exception(sprintf(
                "Safety Violation: Path for %s escapes project directory: '%s'",
                $label,
                $path
            ));
        }
    }

    /**
     * Whether the given path is absolute (Unix or Windows).
     */
    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Unix absolute.
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        // Windows drive letter, e.g. C:\ or C:/
        return strlen($path) > 2
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && ($path[2] === '\\' || $path[2] === '/');
    }

    /**
     * Normalize a path: unify separators and resolve . / .. segments
     * without requiring the path to exist on disk.
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $stack = [];

        $isWindowsAbs = strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':';
        $isUnixAbs = str_starts_with($path, DIRECTORY_SEPARATOR);

        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.') {
                // Keep Windows drive letter segment (C:).
                if ($index === 0 && $isWindowsAbs && str_ends_with($part . ':', ':') === false && str_contains($parts[0], ':')) {
                    $stack[] = $part;
                }
                continue;
            }

            if ($index === 0 && $isWindowsAbs && str_contains($part, ':')) {
                $stack[] = $part;
                continue;
            }

            if ($part === '..') {
                if ($stack !== [] && end($stack) !== '..' && !str_contains((string) end($stack), ':')) {
                    array_pop($stack);
                }
                continue;
            }

            $stack[] = $part;
        }

        $normalized = implode(DIRECTORY_SEPARATOR, $stack);

        if ($isUnixAbs) {
            $normalized = DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
        }

        if ($normalized === '' && $isUnixAbs) {
            return DIRECTORY_SEPARATOR;
        }

        return $normalized;
    }
}
