<?php

namespace PhpMake\Utilities;

/** Writes console messages and optionally persists them to a rotating log. */
final class Logger
{
    private const int MAX_SIZE = 10 * 1024 * 1024;
    private const int MAX_FILES = 5;

    /** Configures debug output, console silence, file logging, and an optional log path. */
    public function __construct(
        private readonly bool $debug,
        private readonly bool $silent,
        private readonly bool $noLog,
        private readonly string $logFile = 'build.log'
    ) {}

    /** Writes an informational message. */
    public function info(string $message): void
    {
        if (!$this->silent) {
            echo $message . PHP_EOL;
        }
        $this->writeLog('INFO: ' . $message);
    }

    /** Writes a debug message when debug output is enabled. */
    public function debug(string $message): void
    {
        if ($this->debug && !$this->silent) {
            echo 'DEBUG: ' . $message . PHP_EOL;
        }
        $this->writeLog('DEBUG: ' . $message);
    }

    /** Writes an error message to standard error. */
    public function error(string $message): void
    {
        fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
        $this->writeLog('ERROR: ' . $message);
    }

    /** File logging is best-effort and must never interrupt the build. */
    private function writeLog(string $message): void
    {
        if ($this->noLog) {
            return;
        }

        try {
            $this->logToFile($message);
        } catch (\Throwable) {
            // Console/build behavior takes precedence over optional log persistence.
        }
    }

    /**
     * Serialize size checks, rotation and writes across processes.
     * Keep the lock file persistent to avoid lock inode races.
     */
    private function logToFile(string $message): void
    {
        $lock = @fopen($this->logFile . '.lock', 'c');
        if ($lock === false) {
            return;
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return;
            }

            clearstatcache(true, $this->logFile);
            if (is_file($this->logFile) && filesize($this->logFile) >= self::MAX_SIZE) {
                $oldest = $this->logFile . '.' . self::MAX_FILES;
                if (file_exists($oldest) && !@unlink($oldest)) {
                    throw new \RuntimeException(sprintf("Unable to remove old log '%s'.", $oldest));
                }

                for ($i = self::MAX_FILES - 1; $i >= 1; --$i) {
                    $from = $this->logFile . '.' . $i;
                    $to = $this->logFile . '.' . ($i + 1);
                    if (file_exists($from) && !@rename($from, $to)) {
                        throw new \RuntimeException(sprintf("Unable to rotate log '%s'.", $from));
                    }
                }

                if (!@rename($this->logFile, $this->logFile . '.1')) {
                    throw new \RuntimeException(sprintf("Unable to rotate log '%s'.", $this->logFile));
                }
            }

            $fp = @fopen($this->logFile, 'ab');
            if ($fp === false) {
                return;
            }

            try {
                $line = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
                $offset = 0;
                $length = strlen($line);
                while ($offset < $length) {
                    $written = fwrite($fp, substr($line, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Unable to write complete build log entry.');
                    }
                    $offset += $written;
                }
                fflush($fp);
            } finally {
                fclose($fp);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Indicates whether debug messages are visible. */
    public function isDebugEnabled(): bool
    {
        return $this->debug && !$this->silent;
    }

    /** Indicates whether regular console output is suppressed. */
    public function isSilent(): bool
    {
        return $this->silent;
    }
}
