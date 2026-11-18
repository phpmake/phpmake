<?php

declare(strict_types=1);

namespace PhpMake\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Verifies that --init never follows an existing dangling destination symlink. */
final class InitSymlinkTest extends TestCase
{
    /** A dangling build.json symlink is rejected without creating its target. */
    public function testInitRejectsDanglingBuildConfigSymlink(): void
    {
        // Arrange
        $root = dirname(__DIR__, 2);
        $project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-init-' . bin2hex(random_bytes(8));
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-target-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($project));
        $link = $project . DIRECTORY_SEPARATOR . 'build.json';
        if (!@symlink($target, $link)) {
            rmdir($project);
            self::assertFalse(is_link($link));
            return;
        }

        try {
            $process = proc_open(
                [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpmake.php', '--init'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $project
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            // Assert
            self::assertSame(1, proc_close($process), $stdout . $stderr);
            self::assertTrue(is_link($link));
            self::assertFileDoesNotExist($target);
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            if (is_file($target)) {
                unlink($target);
            }
            rmdir($project);
        }
    }
}
