<?php

namespace PhpMake\Utilities;

use PhpMake\Cli\ConsoleOutput;

/** Writes console messages and optionally persists them to a rotating log. */
final class Logger
{
    private const MAX_SIZE = 10 * 1024 * 1024;

    private const MAX_FILES = 5;

    private ConsoleOutput $output;

    /** Configures debug output, console silence, file logging, and an optional log path. */
    public function __construct(
        private bool $debug,
        private bool $silent,
        private bool $noLog,
        private string $logFile = 'build.log',
        ?ConsoleOutput $output = null
    ) {
        $this->output = $output ?? new ConsoleOutput();
    }

    /** Writes an informational message. */
    public function info(string $message): void
    {
        if (!$this->silent) {
            $this->output->info($message);
        }

        $this->writeLog('INFO: ' . $message);
    }

    /** Writes a debug message when debug output is enabled. */
    public function debug(string $message): void
    {
        if ($this->debug && !$this->silent) {
            $this->output->info('DEBUG: ' . $message);
        }

        $this->writeLog('DEBUG: ' . $message);
    }

    /** Writes an error message to standard error. */
    public function error(string $message): void
    {
        $this->output->error('Error: ' . $message);
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
        $lockPath = $this->logFile . '.lock';
        $lock = $this->openFileSafely($lockPath, 'c');
        if ($lock === false) {
            return;
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return;
            }

            $this->assertSafeLocalFilePath($this->logFile);
            clearstatcache(true, $this->logFile);
            if (is_file($this->logFile) && filesize($this->logFile) >= self::MAX_SIZE) {
                $oldest = $this->logFile . '.' . self::MAX_FILES;
                $this->assertSafeLocalFilePath($oldest);
                if (file_exists($oldest) && !@unlink($oldest)) {
                    throw new \RuntimeException(sprintf("Unable to remove old log '%s'.", $oldest));
                }

                for ($i = self::MAX_FILES - 1; $i >= 1; --$i) {
                    $from = $this->logFile . '.' . $i;
                    $to = $this->logFile . '.' . ($i + 1);
                    $this->assertSafeLocalFilePath($from);
                    $this->assertSafeLocalFilePath($to);
                    if (file_exists($from) && !@rename($from, $to)) {
                        throw new \RuntimeException(sprintf("Unable to rotate log '%s'.", $from));
                    }
                }

                $this->assertSafeLocalFilePath($this->logFile . '.1');
                if (!@rename($this->logFile, $this->logFile . '.1')) {
                    throw new \RuntimeException(sprintf("Unable to rotate log '%s'.", $this->logFile));
                }
            }

            $fp = $this->openFileSafely($this->logFile, 'ab');
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

    /**
     * Opens local log files only when the path and opened handle identify the
     * same regular, single-link file. Non-local stream wrappers retain their
     * existing behavior for test and custom stream integrations.
     *
     * @return resource|false
     */
    private function openFileSafely(string $path, string $mode)
    {
        if (!self::isLocalFilesystemPath($path)) {
            return @fopen($path, $mode);
        }

        if (!self::isSafeLocalFilePath($path)) {
            return false;
        }

        $handle = @fopen($path, $mode);
        if ($handle === false) {
            return false;
        }

        $pathStat = @lstat($path);
        $handleStat = @fstat($handle);
        if (!is_array($pathStat) || !is_array($handleStat)
            || !self::isRegularSingleLinkFile($pathStat)
            || !self::isRegularSingleLinkFile($handleStat)
            || !isset($pathStat['dev'], $pathStat['ino'], $handleStat['dev'], $handleStat['ino'])
            || $pathStat['dev'] !== $handleStat['dev']
            || $pathStat['ino'] !== $handleStat['ino']) {
            fclose($handle);
            return false;
        }

        return $handle;
    }

    /** Rejects local symlinks, special files, and hardlinks before metadata access or rotation. */
    private function assertSafeLocalFilePath(string $path): void
    {
        if (self::isLocalFilesystemPath($path) && !self::isSafeLocalFilePath($path)) {
            throw new \RuntimeException(sprintf("Refusing unsafe log path '%s'.", $path));
        }
    }

    /** Returns whether a missing path or a regular single-link local file is safe to use. */
    private static function isSafeLocalFilePath(string $path): bool
    {
        $stat = @lstat($path);
        return $stat === false || self::isRegularSingleLinkFile($stat);
    }

    /** Identifies local filesystem paths while leaving custom stream wrappers untouched. */
    private static function isLocalFilesystemPath(string $path): bool
    {
        if (strlen($path) >= 3 && preg_match('/^[A-Za-z]$/D', $path[0]) === 1
            && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/')) {
            return true;
        }

        $scheme = parse_url($path, PHP_URL_SCHEME);
        return $scheme === null || (is_string($scheme) && strtolower($scheme) === 'file');
    }

    /** Determines whether stat metadata describes a regular file with no additional hardlinks. */
    private static function isRegularSingleLinkFile(array $stat): bool
    {
        return isset($stat['mode'])
            && (($stat['mode'] & 0170000) === 0100000)
            && (!isset($stat['nlink']) || $stat['nlink'] <= 1);
    }

    /** Indicates whether debug logging was requested, independent of console silence. */
    public function isDebugRequested(): bool
    {
        return $this->debug;
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
