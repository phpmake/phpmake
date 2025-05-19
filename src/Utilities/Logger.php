<?php

namespace PhpMake\Utilities;

/**
 * Logger Class
 *
 * Implements a simple logging system for PHPMake tool.
 * Supports different logging levels including info, debug, and error.
 * Allows logging control via debug, silent, and no-log flags.
 *
 * @package    PHPMake
 * @subpackage Utilities
 */
final class Logger
{
    /**
     * @var string Path to the log file ('build.log' by default).
     */
    private string $logFile = 'build.log';

    /**
     * Constructor
     *
     * Initializes logger with debug, silent, and no-log flags.
     *
     * @param bool $debug  Enable debug mode.
     * @param bool $silent Suppress output mode.
     * @param bool $noLog  Disable file logging.
     */
    public function __construct(private readonly bool $debug, private readonly bool $silent, private readonly bool $noLog) {}

    /**
     * Log an informational message.
     *
     * Outputs message unless silent mode is enabled.
     * Writes message to log file if logging is enabled.
     *
     * @param string $message The message to log.
     */
    public function info(string $message): void
    {
        if (!$this->silent) {
            echo $message . PHP_EOL;
        }

        if (!$this->noLog) {
            $this->logToFile('INFO: ' . $message);
        }
    }

    /**
     * Log a debug message.
     *
     * Outputs debug message only if debug mode is enabled.
     * Writes message to log file if logging is enabled.
     *
     * @param string $message The debug message to log.
     */
    public function debug(string $message): void
    {
        if ($this->debug && !$this->silent) {
            echo 'DEBUG: ' . $message . PHP_EOL;
        }

        if (!$this->noLog) {
            $this->logToFile('DEBUG: ' . $message);
        }
    }

    /**
     * Log an error message.
     *
     * Writes error message to STDERR.
     * Writes message to log file if logging is enabled.
     *
     * @param string $message The error message to log.
     */
    public function error(string $message): void
    {
        fwrite(STDERR, 'Error: ' . $message . PHP_EOL);

        if (!$this->noLog) {
            $this->logToFile('ERROR: ' . $message);
        }
    }

    /**
     * Write log message to file.
     *
     * Appends message to log file unless no-log mode is enabled.
     *
     * @param string $message The message to log.
     */
    private function logToFile(string $message): void
    {
        $this->rotateLogs();
        file_put_contents(
            $this->logFile,
            date('Y-m-d H:i:s') . (' ' . $message) . PHP_EOL,
            FILE_APPEND
        );
    }

    private function rotateLogs(): void
    {
        $maxSize = 10 * 1024 * 1024; // 10MB.
        $maxFiles = 5;

        if (file_exists($this->logFile) && filesize($this->logFile) >= $maxSize) {
            for ($i = $maxFiles - 1; $i >= 1; $i--) {
                $old = sprintf('%s.%d', $this->logFile, $i);
                $new = $this->logFile . '.' . ($i + 1);
                if (file_exists($old)) {
                    rename($old, $new);
                }
            }

            rename($this->logFile, $this->logFile . '.1');
        }
    }

    /**
     * Check if debug mode is enabled.
     *
     * Returns true if debug mode is enabled and silent mode is disabled.
     *
     * @return bool Debug mode status.
     */
    public function isDebugEnabled(): bool
    {
        return $this->debug && !$this->silent;
    }

    /**
     * Check if silent mode is enabled.
     *
     * Returns true if silent mode is enabled.
     *
     * @return bool Silent mode status.
     */
    public function isSilent(): bool
    {
        return $this->silent;
    }
}
