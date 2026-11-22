<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Covers logger persistence, lock handling, rotation, and best-effort failure isolation. */
final class LoggerPersistenceTest extends TestCase
{
    private string $previousDirectory;

    private string $temporaryDirectory;

    /** Creates an isolated directory for real log files. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDirectory = getcwd();
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-logger-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0777, true));
        self::assertTrue(chdir($this->temporaryDirectory));
    }

    /** Restores the process working directory and removes log artifacts. */
    protected function tearDown(): void
    {
        // Windows does not allow removing the current working directory.
        chdir($this->previousDirectory);
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    /** Debug logging remains available in the file when console output is silent. */
    public function testDebugRequestIsIndependentOfConsoleVisibility(): void
    {
        // Arrange
        $logger = new Logger(true, true, false, 'silent-debug.log');

        // Act
        $logger->debug('diagnostic-entry');

        // Assert
        self::assertTrue($logger->isDebugRequested());
        self::assertFalse($logger->isDebugEnabled());
        self::assertTrue($logger->isSilent());
        self::assertStringContainsString('DEBUG: diagnostic-entry', file_get_contents('silent-debug.log'));
    }

    /** Covers console modes, errors, persistent writes, log rotation, and safe failure modes. */
    public function testLoggerWritesRotatesAndSurvivesPersistenceFailures(): void
    {
        // Arrange
        $visible = new Logger(true, false, false, 'visible.log');
        ob_start();
        try {
            // Act
            $visible->info('info-line');
            $visible->debug('debug-line');
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        // Assert
        self::assertIsString($output);
        self::assertStringContainsString('info-line', $output);
        self::assertStringContainsString('DEBUG: debug-line', $output);
        self::assertTrue($visible->isDebugEnabled());
        self::assertFalse($visible->isSilent());
        self::assertStringContainsString('INFO: info-line', file_get_contents('visible.log'));
        self::assertStringContainsString('DEBUG: debug-line', file_get_contents('visible.log'));

        $silent = new Logger(false, true, false, 'silent.log');
        ob_start();
        try {
            $silent->info('hidden-info');
            $silent->debug('hidden-debug');
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('', $output);
        self::assertFalse($silent->isDebugEnabled());
        self::assertTrue($silent->isSilent());
        self::assertStringContainsString('hidden-info', file_get_contents('silent.log'));

        $errors = new Logger(false, true, true);
        $errors->error('stderr-message');

        // A missing lock directory exercises the non-fatal lock-open failure.
        (new Logger(false, true, false, 'missing-directory/build.log'))->info('lock open failure');

        if (!in_array('phpmake-log-rename-fail', stream_get_wrappers(), true)) {
            self::assertTrue(stream_wrapper_register('phpmake-log-rename-fail', RenameFailureLogStreamWrapper::class));
        }

        if (!in_array('phpmake-log-write-fail', stream_get_wrappers(), true)) {
            self::assertTrue(stream_wrapper_register('phpmake-log-write-fail', WriteFailureLogStreamWrapper::class));
        }

        try {
            RenameFailureLogStreamWrapper::$backupFourExists = true;
            (new Logger(false, true, false, 'phpmake-log-rename-fail://logs/current.log'))->info('backup rotation failure');
            RenameFailureLogStreamWrapper::$backupFourExists = false;
            (new Logger(false, true, false, 'phpmake-log-rename-fail://logs/current.log'))->info('main log rotation failure');
            (new Logger(false, true, false, 'phpmake-log-write-fail://logs/write.log'))->info('write failure');
        } finally {
            stream_wrapper_unregister('phpmake-log-rename-fail');
            stream_wrapper_unregister('phpmake-log-write-fail');
        }

        file_put_contents('rotating.log', str_repeat('x', 10 * 1024 * 1024));
        for ($index = 1; $index <= 5; ++$index) {
            file_put_contents('rotating.log.' . $index, 'old-' . $index);
        }

        (new Logger(false, true, false, 'rotating.log'))->info('new-entry');

        self::assertStringContainsString('new-entry', file_get_contents('rotating.log'));
        self::assertSame('old-4', file_get_contents('rotating.log.5'));
        self::assertSame('old-3', file_get_contents('rotating.log.4'));
        self::assertSame('old-2', file_get_contents('rotating.log.3'));
        self::assertSame('old-1', file_get_contents('rotating.log.2'));
        self::assertGreaterThan(0, filesize('rotating.log.1'));

        mkdir('directory-log');
        (new Logger(false, true, false, 'directory-log'))->info('cannot-open-directory-as-log');

        if (!in_array('phpmake-lock-fail', stream_get_wrappers(), true)) {
            self::assertTrue(stream_wrapper_register('phpmake-lock-fail', LockFailureStreamWrapper::class));
        }

        if (!in_array('phpmake-open-throws', stream_get_wrappers(), true)) {
            self::assertTrue(stream_wrapper_register('phpmake-open-throws', OpenFailureStreamWrapper::class));
        }

        try {
            (new Logger(false, true, false, 'phpmake-lock-fail://log'))->info('lock failure is non-fatal');
            (new Logger(false, true, false, 'phpmake-open-throws://log'))->info('open exception is non-fatal');
        } finally {
            stream_wrapper_unregister('phpmake-lock-fail');
            stream_wrapper_unregister('phpmake-open-throws');
        }

        (new Logger(false, true, true, 'disabled.log'))->info('no log');
        self::assertFileDoesNotExist('disabled.log');
    }


    /** Writes complete log entries even when the underlying stream accepts partial chunks. */
    public function testLoggerRetriesPartialWrites(): void
    {
        // Arrange
        self::assertTrue(stream_wrapper_register('phpmake-partial-log', PartialWriteLogStreamWrapper::class));
        PartialWriteLogStreamWrapper::$contents = '';
        try {
            // Act
            (new Logger(false, true, false, 'phpmake-partial-log://build.log'))->info('partial-write-entry');

            // Assert
            self::assertStringContainsString('INFO: partial-write-entry', PartialWriteLogStreamWrapper::$contents);
            self::assertSame(1, substr_count(PartialWriteLogStreamWrapper::$contents, 'INFO: partial-write-entry'));
        } finally {
            stream_wrapper_unregister('phpmake-partial-log');
        }
    }

    /** Verifies concurrent processes append every log entry without interleaving or loss. */
    public function testConcurrentLoggerProcessesPreserveEveryEntry(): void
    {
        // Arrange
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        self::assertFileExists($autoload);
        $logFile = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'concurrent.log';
        $workerCount = 6;
        $messagesPerWorker = 25;
        $workerCode = 'require $argv[1]; $logger = new \\PhpMake\\Utilities\\Logger(false, true, false, $argv[2]);'
            . ' for ($i = 0; $i < (int) $argv[4]; ++$i) { $logger->info($argv[3] . "-" . $i); }';
        $processes = [];

        for ($worker = 0; $worker < $workerCount; ++$worker) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $workerCode, $autoload, $logFile, 'worker-' . $worker, (string) $messagesPerWorker],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->temporaryDirectory
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        // Act
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $stdout . (string) $stderr);
        }

        // Assert
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount($workerCount * $messagesPerWorker, $lines);
        $entries = [];
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/INFO: worker-(\\d+)-(\\d+)$/', $line);
            preg_match('/INFO: worker-(\\d+)-(\\d+)$/', $line, $matches);
            $entries[$matches[1] . '-' . $matches[2]] = true;
        }

        self::assertCount($workerCount * $messagesPerWorker, $entries);
    }

    /** Recursively removes test directories without following symbolic links. */
    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            }
        }

        rmdir($directory);
    }
}

/** Opens successfully but deliberately rejects exclusive locks. */
final class LockFailureStreamWrapper
{
    public mixed $context;

    /** Creates the wrapper stream. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /** Reports a lock failure to Logger's best-effort persistence layer. */
    public function stream_lock(int $operation): bool
    {
        return false;
    }

    /** Accepts a write so the wrapper satisfies stream expectations. */
    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    /** Returns wrapper stream metadata. */
    public function stream_stat(): array
    {
        return ['mode' => 0100666, 'size' => 0];
    }
}

/** Throws while opening to exercise Logger's catch-all persistence guard. */
final class OpenFailureStreamWrapper
{
    public mixed $context;

    /** Throws an ordinary runtime exception when the log lock is opened. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        throw new \RuntimeException('Simulated log stream failure.');
    }
}


/** Simulates a large log file whose backup or primary rotation fails. */
final class RenameFailureLogStreamWrapper
{
    public static bool $backupFourExists = false;

    public mixed $context;

    /** Opens the virtual stream and lets Logger acquire its lock. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /** Allows Logger to acquire and release its virtual lock. */
    public function stream_lock(int $operation): bool
    {
        return true;
    }

    /** Reports the configured log and optional backup as existing files. */
    public function url_stat(string $path, int $flags): array|false
    {
        if (str_ends_with($path, '.4') && self::$backupFourExists) {
            return $this->stat(1);
        }

        if (str_ends_with($path, 'current.log')) {
            return $this->stat(10 * 1024 * 1024);
        }

        return false;
    }

    /** Fails both backup and primary rotation to exercise Logger's exception guards. */
    public function url_rename(string $from, string $to): bool
    {
        return false;
    }

    /** Accepts stream writes when no failure is being simulated. */
    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    /** Returns stat information for virtual files. */
    private function stat(int $size): array
    {
        return [
            2 => 0100666,
            7 => $size,
            'mode' => 0100666,
            'size' => $size,
        ];
    }
}

/** Simulates a writable log stream whose write operation fails. */
final class WriteFailureLogStreamWrapper
{
    public mixed $context;

    /** Opens the virtual log and lock streams. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    /** Allows Logger to acquire and release its lock. */
    public function stream_lock(int $operation): bool
    {
        return true;
    }

    /** Returns regular-file metadata so the log can be opened for append. */
    public function url_stat(string $path, int $flags): array
    {
        return [
            2 => 0100666,
            7 => 0,
            'mode' => 0100666,
            'size' => 0,
        ];
    }

    /** Forces fwrite() to return false so Logger handles the persistence failure. */
    public function stream_write(string $data): bool
    {
        return false;
    }
}


/** Captures partial writes to exercise Logger's write-all loop. */
final class PartialWriteLogStreamWrapper
{
    public static string $contents = '';

    public mixed $context;

    private string $path = '';

    /** Opens the virtual lock or log stream. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = $path;
        return true;
    }

    /** Allows Logger to serialize access to the virtual stream. */
    public function stream_lock(int $operation): bool
    {
        return true;
    }

    /** Accepts only a short prefix to force repeated fwrite calls. */
    public function stream_write(string $data): int
    {
        if (str_ends_with($this->path, '.lock')) {
            return strlen($data);
        }

        $chunk = substr($data, 0, 3);
        self::$contents .= $chunk;
        return strlen($chunk);
    }

    /** Reports no existing log so rotation is not attempted. */
    public function url_stat(string $path, int $flags): array|false
    {
        return false;
    }

    /** Returns metadata for an opened virtual stream. */
    public function stream_stat(): array
    {
        return ['mode' => 0100666, 'size' => strlen(self::$contents)];
    }

    /** Confirms buffered writes are accepted. */
    public function stream_flush(): bool
    {
        return true;
    }
}
