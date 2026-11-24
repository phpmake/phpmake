<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Process\PosixProcessLauncher;
use PHPUnit\Framework\TestCase;

/** Tests POSIX process-group launcher preparation using real subprocesses and utilities. */
final class PosixProcessLauncherTest extends TestCase
{
    /** Prepares valid launcher metadata and preserves the original argv payload. */
    public function testPrepareReturnsConsistentProcessGroupMetadata(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The POSIX process launcher is available only on Linux.');
        }

        $arguments = [PHP_BINARY, '-r', 'echo "process";', 'argument with spaces'];

        // Act
        $prepared = PosixProcessLauncher::prepare($arguments);

        try {
            // Assert
            self::assertSame(['argv', 'pidPath', 'processGroupCreated'], array_keys($prepared));
            self::assertNotEmpty($prepared['argv']);
            self::assertIsBool($prepared['processGroupCreated']);
            self::assertTrue($prepared['pidPath'] === null || is_string($prepared['pidPath']));

            if ($prepared['processGroupCreated']) {
                self::assertContains(
                    base64_encode(json_encode($arguments, JSON_THROW_ON_ERROR)),
                    $prepared['argv']
                );
            } else {
                self::assertSame($arguments, $prepared['argv']);
                self::assertNull($prepared['pidPath']);
            }
        } finally {
            if (is_string($prepared['pidPath']) && is_file($prepared['pidPath'])) {
                unlink($prepared['pidPath']);
            }
        }
    }

    /** Uses setsid and kill when the POSIX extension is unavailable. */
    public function testPrepareUsesExecutableFallbackWithoutPosixExtension(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The POSIX launcher fallback is available only on Linux.');
        }

        if (!$this->findExecutable('setsid') || !$this->findExecutable('kill')) {
            self::markTestSkipped('The setsid and kill utilities are required for this fallback test.');
        }

        $arguments = [PHP_BINARY, '-r', 'echo "fallback";'];

        // Act
        $result = $this->runPhpWithoutExtensions($this->buildPreparationScript(), $arguments);
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        try {
            // Assert
            self::assertSame(0, $result['exitCode']);
            self::assertSame('', $result['stderr']);
            self::assertFalse($payload['posixAvailable']);
            self::assertTrue($payload['result']['processGroupCreated']);
            self::assertIsString($payload['result']['pidPath']);
            self::assertContains(
                base64_encode(json_encode($arguments, JSON_THROW_ON_ERROR)),
                $payload['result']['argv']
            );
        } finally {
            $pidPath = $payload['result']['pidPath'] ?? null;
            if (is_string($pidPath) && is_file($pidPath)) {
                unlink($pidPath);
            }
        }
    }

    /** Keeps the original argv when neither POSIX functions nor fallback utilities exist. */
    public function testPrepareFallsBackToOriginalArgumentsWhenUtilitiesAreUnavailable(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The POSIX launcher fallback is available only on Linux.');
        }

        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-empty-path-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $arguments = [PHP_BINARY, '-r', 'echo "unchanged";'];

        try {
            // Act
            $result = $this->runPhpWithoutExtensions(
                $this->buildPreparationScript(),
                $arguments,
                $directory
            );
            $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

            // Assert
            self::assertSame(0, $result['exitCode']);
            self::assertSame('', $result['stderr']);
            self::assertFalse($payload['posixAvailable']);
            self::assertFalse($payload['result']['processGroupCreated']);
            self::assertNull($payload['result']['pidPath']);
            self::assertSame($arguments, $payload['result']['argv']);
        } finally {
            rmdir($directory);
        }
    }

    /** Builds a standalone script that loads the real production launcher. */
    private function buildPreparationScript(): string
    {
        $root = dirname(__DIR__, 2);

        return sprintf(
            'require %s; require %s; '
            . '$arguments = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR); '
            . '$result = \PhpMake\Process\PosixProcessLauncher::prepare($arguments); '
            . 'echo json_encode(['
            . '"posixAvailable" => function_exists("posix_setsid") && function_exists("posix_kill"), '
            . '"result" => $result'
            . '], JSON_THROW_ON_ERROR);',
            var_export($root . '/src/PlatformSupport.php', true),
            var_export($root . '/src/Process/PosixProcessLauncher.php', true)
        );
    }

    /**
     * Runs a PHP subprocess with all extensions disabled.
     *
     * @param list<string> $arguments
     * @return array{exitCode:int,stdout:string,stderr:string}
     */
    private function runPhpWithoutExtensions(string $script, array $arguments, ?string $path = null): array
    {
        $process = proc_open(
            [PHP_BINARY, '-n', '-r', $script, base64_encode(json_encode($arguments, JSON_THROW_ON_ERROR))],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            $path === null ? null : ['PATH' => $path],
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the PHP subprocess.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    /** Finds an executable using the current PATH for a test precondition. */
    private function findExecutable(string $name): ?string
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
