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

    private const int MAX_SIZE = 10 * 1024 * 1024; // 10MB.

    private const int MAX_FILES = 5;

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
     * Write log message to file under an exclusive lock.
     *
     * Rotates the log when size exceeds the limit, keeps at most MAX_FILES
     * rotated files, and deletes older ones.
     *
     * @param string $message The message to log.
     */
    private function logToFile(string $message): void
    {
        $line = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;

        $fp = @fopen($this->logFile, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }

            // Move to end to measure current size accurately under lock.
            fseek($fp, 0, SEEK_END);
            $size = ftell($fp);

            if ($size !== false && $size >= self::MAX_SIZE) {
                flock($fp, LOCK_UN);
                fclose($fp);
                $this->rotateLogs();

                $fp = @fopen($this->logFile, 'a');
                if ($fp === false) {
                    return;
                }
                if (!flock($fp, LOCK_EX)) {
                    fclose($fp);
                    return;
                }
            }

            fwrite($fp, $line);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * Rotate log files under a lock file to avoid concurrent corruption.
     * Keeps at most MAX_FILES rotated copies; deletes the oldest.
     */
    private function rotateLogs(): void
    {
        $lockFile = $this->logFile . '.rotate.lock';
        $lockFp = @fopen($lockFile, 'c');
        if ($lockFp === false) {
            return;
        }

        try {
            if (!flock($lockFp, LOCK_EX)) {
                return;
            }

            // Re-check size after acquiring rotate lock (another process may have rotated).
            if (!file_exists($this->logFile) || filesize($this->logFile) < self::MAX_SIZE) {
                return;
            }

            // Drop the oldest rotated file beyond the limit.
            $oldest = $this->logFile . '.' . self::MAX_FILES;
            if (file_exists($oldest)) {
                @unlink($oldest);
            }

            // Shift: .(n) -> .(n+1)
            for ($i = self::MAX_FILES - 1; $i >= 1; $i--) {
                $from = $this->logFile . '.' . $i;
                $to = $this->logFile . '.' . ($i + 1);
                if (file_exists($from)) {
                    @rename($from, $to);
                }
            }

            @rename($this->logFile, $this->logFile . '.1');
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            @unlink($lockFile);
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
