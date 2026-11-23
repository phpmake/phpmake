<?php

declare(strict_types=1);

namespace PhpMake\Tests\Support;

use PhpMake\PlatformSupport;

/** Creates directory links on Linux and unprivileged Windows environments. */
trait DirectoryLinkTestHelper
{
    /** Detects a Windows NTFS junction without following its target. */
    private function isDirectoryJunction(string $path): bool
    {
        PlatformSupport::assertSupported();

        clearstatcache(true, $path);
        if (PHP_OS_FAMILY !== 'Windows' || !is_dir($path) || is_link($path)) {
            return false;
        }

        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] & 0xF000) !== 0x4000);
    }

    /** Removes a directory link without traversing its target. */
    private function removeDirectoryLink(string $path): void
    {
        PlatformSupport::assertSupported();

        if (PHP_OS_FAMILY === 'Windows') {
            // PHP's rmdir()/unlink() handling of directory symlinks varies
            // across Windows PHP builds; cmd's rmdir removes the link itself.
            exec('cmd /c rmdir ' . escapeshellarg($path), $output, $exitCode);
            if ($exitCode === 0) {
                return;
            }

            if (@rmdir($path)) {
                return;
            }
        }

        unlink($path);
    }

    /** Creates a symbolic link or, on Windows, an NTFS junction. */
    private function createDirectoryLink(string $target, string $link): void
    {
        PlatformSupport::assertSupported();

        if (PHP_OS_FAMILY === 'Windows') {
            $powerShell = (getenv('SystemRoot') ?: 'C:\\Windows')
                . DIRECTORY_SEPARATOR . 'System32'
                . DIRECTORY_SEPARATOR . 'WindowsPowerShell'
                . DIRECTORY_SEPARATOR . 'v1.0'
                . DIRECTORY_SEPARATOR . 'powershell.exe';

            // PowerShell single-quoted strings safely preserve spaces and
            // metacharacters in filesystem paths without cmd.exe quoting rules.
            $quotePath = static fn(string $path): string => "'"
                . str_replace("'", "''", $path)
                . "'";
            $script = '$ErrorActionPreference = "Stop"; '
                . 'New-Item -ItemType Junction -Path ' . $quotePath($link)
                . ' -Target ' . $quotePath($target)
                . ' -ErrorAction Stop | Out-Null';

            $process = @proc_open(
                [$powerShell, '-NoProfile', '-NonInteractive', '-Command', $script],
                [
                    0 => ['file', 'NUL', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );

            $junctionOutput = '';
            $junctionError = '';
            $junctionExitCode = -1;
            if (is_resource($process)) {
                $junctionOutput = (string) stream_get_contents($pipes[1]);
                $junctionError = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $junctionExitCode = proc_close($process);
                clearstatcache(true, $link);
                if ($junctionExitCode === 0 && $this->isDirectoryJunction($link)) {
                    return;
                }
            } else {
                $junctionError = 'Unable to start Windows PowerShell.';
            }
        }

        if (@symlink($target, $link)) {
            clearstatcache(true, $link);
            if (is_link($link) || $this->isDirectoryJunction($link)) {
                return;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            self::fail(sprintf(
                'Unable to create a directory junction or symlink from "%s" to "%s". PowerShell exit code: %d; stdout: %s; stderr: %s',
                $link,
                $target,
                $junctionExitCode,
                trim($junctionOutput),
                trim($junctionError)
            ));
        }

        self::fail(sprintf('Unable to create a directory symlink from "%s" to "%s".', $link, $target));
    }
}
