<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Exercises process-signal utility fallbacks with real PHP subprocesses and no POSIX extension. */
final class ProcessSupervisorFallbackTest extends TestCase
{
    /** Uses the kill utility to terminate a tracked process when posix_kill is unavailable. */
    public function testTerminateUsesKillUtilityWithoutPosixExtension(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The POSIX signal fallback is available only on Linux.');
        }

        if (!$this->findExecutable('kill')) {
            self::markTestSkipped('The kill utility is required for this fallback test.');
        }

        $script = $this->buildProcessSignalScript();

        // Act
        $result = $this->runPhpWithoutExtensions($script);

        // Assert
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('terminated', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    /** Uses the kill utility to terminate a process group when posix_kill is unavailable. */
    public function testTerminateUsesKillUtilityForProcessGroupsWithoutPosixExtension(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The POSIX process-group fallback is available only on Linux.');
        }

        $setsid = $this->findExecutable('setsid');
        if ($setsid === null || !$this->findExecutable('kill')) {
            self::markTestSkipped('The setsid and kill utilities are required for this fallback test.');
        }

        $script = $this->buildProcessGroupSignalScript($setsid);

        // Act
        $result = $this->runPhpWithoutExtensions($script);

        // Assert
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('group-terminated', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    /** Builds a subprocess script that exercises the single-process signal fallback. */
    private function buildProcessSignalScript(): string
    {
        $root = dirname(__DIR__, 2);

        return sprintf(
            'require %s; require %s; '
            . '$process = proc_open([PHP_BINARY, "-n", "-r", "sleep(10);"], '
            . '[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes); '
            . 'if (!is_resource($process)) { exit(20); } '
            . 'foreach ($pipes as $pipe) { fclose($pipe); } '
            . '$pid = (int) proc_get_status($process)["pid"]; '
            . '\PhpMake\Process\ProcessSupervisor::terminate($process, $pid, false, null, [$pid]); '
            . '$running = (bool) (proc_get_status($process)["running"] ?? false); '
            . 'if ($running) { proc_terminate($process, 9); } '
            . 'proc_close($process); '
            . 'if ($running) { exit(21); } '
            . 'echo "terminated";',
            var_export($root . '/src/PlatformSupport.php', true),
            var_export($root . '/src/Process/ProcessSupervisor.php', true)
        );
    }

    /** Builds a subprocess script that verifies group signaling also stops its child. */
    private function buildProcessGroupSignalScript(string $setsid): string
    {
        $root = dirname(__DIR__, 2);
        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-group-fallback-' . bin2hex(random_bytes(8));
        $childCode = 'usleep(1200000); file_put_contents($argv[1], "survived");';
        $parentCode = '$pipes = []; '
            . '$child = proc_open([PHP_BINARY, "-n", "-r", '
            . var_export($childCode, true) . ', $argv[1]], '
            . '[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes); '
            . 'if (!is_resource($child)) { exit(30); } '
            . 'foreach ($pipes as $pipe) { fclose($pipe); } '
            . 'sleep(10);';

        return sprintf(
            'require %s; require %s; '
            . '$process = proc_open([%s, PHP_BINARY, "-n", "-r", %s, %s], '
            . '[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes); '
            . 'if (!is_resource($process)) { exit(31); } '
            . 'foreach ($pipes as $pipe) { fclose($pipe); } '
            . '$pid = (int) proc_get_status($process)["pid"]; '
            . '\PhpMake\Process\ProcessSupervisor::terminate($process, $pid, true, $pid); '
            . 'usleep(1500000); '
            . '$survived = is_file(%s); '
            . '$running = (bool) (proc_get_status($process)["running"] ?? false); '
            . 'if ($running) { proc_terminate($process, 9); } '
            . 'proc_close($process); '
            . 'if ($survived) { @unlink(%s); exit(32); } '
            . 'echo "group-terminated";',
            var_export($root . '/src/PlatformSupport.php', true),
            var_export($root . '/src/Process/ProcessSupervisor.php', true),
            var_export($setsid, true),
            var_export($parentCode, true),
            var_export($marker, true),
            var_export($marker, true),
            var_export($marker, true)
        );
    }

    /**
     * Runs a PHP subprocess with all extensions disabled.
     *
     * @return array{exitCode:int,stdout:string,stderr:string}
     */
    private function runPhpWithoutExtensions(string $script): array
    {
        $process = proc_open(
            [PHP_BINARY, '-n', '-r', $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            null,
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
            'stdout' => $stdout === false ? '' : trim($stdout),
            'stderr' => $stderr === false ? '' : trim($stderr),
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
