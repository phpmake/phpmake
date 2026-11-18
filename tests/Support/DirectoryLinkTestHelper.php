<?php

declare(strict_types=1);

namespace PhpMake\Tests\Support;

/** Creates directory links on Unix and unprivileged Windows environments. */
trait DirectoryLinkTestHelper
{
    /** Detects a Windows NTFS junction without following its target. */
    private function isDirectoryJunction(string $path): bool
    {
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
        if (PHP_OS_FAMILY === 'Windows' && is_dir($path)) {
            rmdir($path);
            return;
        }
        unlink($path);
    }

    /** Creates a symbolic link or, on Windows, an NTFS junction. */
    private function createDirectoryLink(string $target, string $link): void
    {
        if (@symlink($target, $link)) {
            clearstatcache(true, $link);
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($target);
            exec($command, $output, $exitCode);
            clearstatcache(true, $link);
            if ($exitCode === 0 && is_dir($link)) {
                return;
            }
        }

        self::fail(sprintf('Unable to create a directory link from "%s" to "%s".', $link, $target));
    }
}
