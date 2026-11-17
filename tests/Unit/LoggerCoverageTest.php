<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Covers logger persistence, lock handling, rotation, and best-effort failure isolation. */
final class LoggerCoverageTest extends TestCase
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
        chdir($this->previousDirectory);
        foreach (glob('*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
        rmdir($this->temporaryDirectory);
        parent::tearDown();
    }

    /** Covers console modes, errors, persistent writes, log rotation, and safe failure modes. */
    public function testLoggerWritesRotatesAndSurvivesPersistenceFailures(): void
    {
        $visible = new Logger(true, false, false, 'visible.log');
        ob_start();
        try {
            $visible->info('info-line');
            $visible->debug('debug-line');
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

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
