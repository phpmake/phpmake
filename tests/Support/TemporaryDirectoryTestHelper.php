<?php

declare(strict_types=1);

namespace PhpMake\Tests\Support;

use PhpMake\Filesystem\FilesystemTree;

/** Creates isolated temporary workspaces and removes them without following links. */
trait TemporaryDirectoryTestHelper
{
    /** Creates a unique canonical temporary directory for a test. */
    private function createTemporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        if (!mkdir($path, 0777, true)) {
            throw new \RuntimeException(sprintf('Unable to create temporary test directory "%s".', $path));
        }

        $canonicalPath = realpath($path);
        if ($canonicalPath === false) {
            FilesystemTree::remove($path);
            throw new \RuntimeException(sprintf('Unable to resolve temporary test directory "%s".', $path));
        }

        return $canonicalPath;
    }

    /** Removes a test workspace recursively without traversing directory links. */
    private function removeTemporaryDirectory(string $path): void
    {
        if (!FilesystemTree::remove($path)) {
            throw new \RuntimeException(sprintf('Unable to remove temporary test directory "%s".', $path));
        }
    }
}
