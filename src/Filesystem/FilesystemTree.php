<?php

declare(strict_types=1);

namespace PhpMake\Filesystem;

/**
 * Performs recursive directory copying and cleanup without following links.
 *
 * The caller remains responsible for validating project-root containment and
 * preventing a destination from being placed inside its source directory.
 */
final class FilesystemTree
{
    /** Copies a directory tree into an existing or newly created destination. */
    public static function copyDirectory(string $source, string $destination): bool
    {
        if (self::isLink($source) || !is_dir($source) || self::isLink($destination)) {
            return false;
        }

        if (!is_dir($destination) && !@mkdir($destination, 0777, true) && !is_dir($destination)) {
            return false;
        }

        $items = @scandir($source);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

            if (self::isLink($sourcePath) || self::isLink($destinationPath)) {
                return false;
            }

            if (is_dir($sourcePath)) {
                if (!self::copyDirectory($sourcePath, $destinationPath)) {
                    return false;
                }

                continue;
            }

            if (!is_file($sourcePath) || !@copy($sourcePath, $destinationPath)) {
                return false;
            }
        }

        return true;
    }

    /** Removes a file or directory tree without traversing symbolic links or junctions. */
    public static function remove(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        $resolvedPath = realpath($path);
        $currentDirectory = realpath(getcwd());
        if ($resolvedPath !== false && (
            dirname($resolvedPath) === $resolvedPath
            || ($currentDirectory !== false && FilesystemIdentity::sameFile($resolvedPath, $currentDirectory))
        )) {
            return false;
        }

        if (self::isLink($path)) {
            return self::removeLink($path);
        }

        if (!is_dir($path)) {
            return !file_exists($path) || @unlink($path);
        }

        $items = @scandir($path);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (!self::remove($path . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return @rmdir($path);
    }

    /** Detects POSIX symbolic links and Windows directory junctions. */
    public static function isLink(string $path): bool
    {
        clearstatcache(true, $path);
        if (PHP_OS_FAMILY !== 'Windows') {
            return is_link($path);
        }

        if (!is_dir($path)) {
            return is_link($path);
        }

        if (is_link($path)) {
            return true;
        }

        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] & 0xF000) !== 0x4000);
    }

    /** Removes a link itself rather than its target. */
    private static function removeLink(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows' && @rmdir($path)) {
            return true;
        }

        return @unlink($path);
    }
}
