<?php

declare(strict_types=1);

namespace PhpMake\Filesystem;

use PhpMake\PlatformSupport;

/**
 * Performs recursive directory copying and cleanup without following links.
 *
 * Directory copies are staged beside the destination and published only after
 * the complete merged tree has been copied successfully.
 */
final class FilesystemTree
{
    /** Copies a directory tree into an existing or newly created destination. */
    public static function copyDirectory(string $source, string $destination): bool
    {
        PlatformSupport::assertSupported();

        if ($source === '' || $destination === '' || str_contains($source, "\0")
            || str_contains($destination, "\0") || self::isLink($source)
            || !is_dir($source) || self::isLink($destination)) {
            return false;
        }

        $sourcePath = realpath($source);
        $destinationPath = self::resolveDestination($destination);
        if ($sourcePath === false || $destinationPath === null
            || self::pathsOverlap($sourcePath, $destinationPath)) {
            return false;
        }

        if (file_exists($destinationPath) && !is_dir($destinationPath)) {
            return false;
        }

        $createdParents = [];
        if (!self::ensureDirectory(dirname($destinationPath), $createdParents)) {
            self::removeCreatedParents($createdParents);
            return false;
        }

        $destinationPath = self::resolveDestination($destinationPath);
        if ($destinationPath === null || self::pathsOverlap($sourcePath, $destinationPath)) {
            self::removeCreatedParents($createdParents);
            return false;
        }

        $staging = dirname($destinationPath) . DIRECTORY_SEPARATOR
            . '.phpmake-tree-' . bin2hex(random_bytes(8)) . '.staging';
        if (!@mkdir($staging, 0777)) {
            self::removeCreatedParents($createdParents);
            return false;
        }

        $copied = !is_dir($destinationPath)
            || self::copyDirectoryContents($destinationPath, $staging);
        if ($copied) {
            $copied = self::copyDirectoryContents($sourcePath, $staging);
        }

        if (!$copied) {
            if (!self::remove($staging)) {
                self::removeCreatedParents($createdParents);
                throw new \RuntimeException(sprintf("Unable to remove incomplete staging tree '%s'.", $staging));
            }

            self::removeCreatedParents($createdParents);
            return false;
        }

        try {
            AtomicDirectoryPublisher::publish($staging, $destinationPath);
        } catch (\Throwable $throwable) {
            if ((file_exists($staging) || is_link($staging)) && !self::remove($staging)) {
                throw new \RuntimeException(
                    sprintf("Unable to remove incomplete staging tree '%s'.", $staging),
                    0,
                    $throwable
                );
            }

            self::removeCreatedParents($createdParents);
            throw $throwable;
        }

        return true;
    }

    /** Removes a file or directory tree without traversing symbolic links or junctions. */
    public static function remove(string $path): bool
    {
        PlatformSupport::assertSupported();

        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        if (self::isLink($path)) {
            return self::removeLink($path);
        }

        $resolvedPath = realpath($path);
        $currentDirectory = realpath(getcwd());
        if ($resolvedPath !== false && (
            dirname($resolvedPath) === $resolvedPath
            || ($currentDirectory !== false && FilesystemIdentity::sameFile($resolvedPath, $currentDirectory))
        )) {
            return false;
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
        PlatformSupport::assertSupported();

        clearstatcache(true, $path);
        if (PHP_OS_FAMILY === 'Linux') {
            return is_link($path);
        }

        if (!is_dir($path)) {
            if (is_link($path)) {
                return true;
            }

            // A dangling Windows junction is no longer a directory, but its
            // reparse-point mode remains visible through lstat().
            $stat = @lstat($path);
            return is_array($stat) && (($stat['mode'] & 0xF000) !== 0x8000);
        }

        if (is_link($path)) {
            return true;
        }

        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] & 0xF000) !== 0x4000);
    }

    /** Copies a directory's contents into an already-created staging directory. */
    private static function copyDirectoryContents(string $source, string $destination): bool
    {
        if (self::isLink($source) || !is_dir($source)
            || self::isLink($destination) || !is_dir($destination)) {
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
                if (file_exists($destinationPath) && !is_dir($destinationPath)) {
                    return false;
                }

                if (!is_dir($destinationPath) && !@mkdir($destinationPath, 0777)) {
                    return false;
                }

                if (!self::copyDirectoryContents($sourcePath, $destinationPath)) {
                    return false;
                }

                continue;
            }

            if (!is_file($sourcePath) || is_dir($destinationPath)
                || !@copy($sourcePath, $destinationPath)) {
                return false;
            }
        }

        return true;
    }

    /** Resolves an existing or not-yet-created destination through its nearest existing parent. */
    private static function resolveDestination(string $path): ?string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            || (PHP_OS_FAMILY === 'Windows' && strlen($path) > 2
                && ctype_alpha($path[0]) && $path[1] === ':' && $path[2] === DIRECTORY_SEPARATOR);
        if (!$isAbsolute) {
            $cwd = getcwd();
            if ($cwd === false) {
                return null;
            }

            $path = $cwd . DIRECTORY_SEPARATOR . $path;
        }

        if (file_exists($path) || self::isLink($path)) {
            $resolved = realpath($path);
            return $resolved === false ? null : $resolved;
        }

        $leaf = basename($path);
        if (in_array($leaf, ['', '.', '..'], true)) {
            return null;
        }

        $parent = dirname($path);
        $missing = [];
        while (!is_dir($parent) && !self::isLink($parent)) {
            $component = basename($parent);
            if (in_array($component, ['', '.', '..'], true)) {
                return null;
            }

            $missing[] = $component;
            $next = dirname($parent);
            if ($next === $parent) {
                return null;
            }

            $parent = $next;
        }

        $resolvedParent = realpath($parent);
        if ($resolvedParent === false || !is_dir($resolvedParent)) {
            return null;
        }

        foreach (array_reverse($missing) as $component) {
            $resolvedParent = self::appendPathComponent($resolvedParent, $component);
            if ($resolvedParent === null) {
                return null;
            }
        }

        return self::appendPathComponent($resolvedParent, $leaf);
    }

    /** Appends one lexical path component without crossing the filesystem root. */
    private static function appendPathComponent(string $base, string $component): ?string
    {
        if ($component === '' || $component === '.') {
            return $base;
        }

        if ($component === '..') {
            $parent = dirname($base);
            return $parent === '' ? DIRECTORY_SEPARATOR : $parent;
        }

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $component;
    }

    /** Returns true when either directory is the same as or an ancestor of the other. */
    private static function pathsOverlap(string $left, string $right): bool
    {
        $left = rtrim($left, DIRECTORY_SEPARATOR);
        $right = rtrim($right, DIRECTORY_SEPARATOR);
        if ($left === '' || $right === '') {
            return true;
        }

        return self::pathEquals($left, $right)
            || self::pathStartsWith($right, $left . DIRECTORY_SEPARATOR)
            || self::pathStartsWith($left, $right . DIRECTORY_SEPARATOR);
    }

    /** Compares path prefixes using conservative Windows case handling. */
    private static function pathStartsWith(string $path, string $prefix): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : str_starts_with($path, $prefix);
    }

    /** Compares complete canonical paths. */
    private static function pathEquals(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    /** Creates a directory and any missing parents, recording newly-created paths for rollback. */
    private static function ensureDirectory(string $directory, array &$created): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        if (self::isLink($directory) || file_exists($directory)) {
            return false;
        }

        $parent = dirname($directory);
        if ($parent === $directory || !self::ensureDirectory($parent, $created)) {
            return false;
        }

        if (@mkdir($directory, 0777)) {
            $created[] = $directory;
            return true;
        }

        // A concurrent process may have created the directory after the
        // initial check. Accept it, but never claim it as ours for rollback.
        return is_dir($directory) && !self::isLink($directory);
    }

    /** Removes only newly-created empty parent directories, from deepest to shallowest. */
    private static function removeCreatedParents(array $created): void
    {
        foreach (array_reverse($created) as $directory) {
            @rmdir($directory);
        }
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
