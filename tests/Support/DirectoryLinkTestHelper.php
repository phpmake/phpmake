<?php

declare(strict_types=1);

namespace PhpMake\Tests\Support;

/** Creates directory links on Unix and unprivileged Windows environments. */
trait DirectoryLinkTestHelper
{
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
