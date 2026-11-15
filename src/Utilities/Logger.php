<?php

namespace PhpMake\Utilities;

final class Logger
{
    private string $logFile = 'build.log';
    private const int MAX_SIZE = 10 * 1024 * 1024;
    private const int MAX_FILES = 5;

    public function __construct(private readonly bool $debug, private readonly bool $silent, private readonly bool $noLog) {}

    public function info(string $message): void
    {
        if (!$this->silent) echo $message . PHP_EOL;
        if (!$this->noLog) $this->logToFile('INFO: ' . $message);
    }

    public function debug(string $message): void
    {
        if ($this->debug && !$this->silent) echo 'DEBUG: ' . $message . PHP_EOL;
        if (!$this->noLog) $this->logToFile('DEBUG: ' . $message);
    }

    public function error(string $message): void
    {
        fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
        if (!$this->noLog) $this->logToFile('ERROR: ' . $message);
    }

    /**
     * A single persistent lock inode serializes size checks, rotation and writes
     * across processes. Do not unlink the lock file: doing so permits inode races.
     */
    private function logToFile(string $message): void
    {
        $lock = @fopen($this->logFile . '.lock', 'c');
        if ($lock === false) return;
        try {
            if (!flock($lock, LOCK_EX)) return;
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
            if ($fp === false) return;
            try {
                if (fwrite($fp, date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL) === false) {
                    throw new \RuntimeException('Unable to write build log.');
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

    public function isDebugEnabled(): bool { return $this->debug && !$this->silent; }
    public function isSilent(): bool { return $this->silent; }
}
