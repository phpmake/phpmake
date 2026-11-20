<?php

declare(strict_types=1);

namespace PhpMake\Filesystem;

/**
 * Publishes a completed directory tree with backup and rollback semantics.
 *
 * The staging directory must be a sibling of the destination so publication
 * uses same-volume rename operations.
 */
final class AtomicDirectoryPublisher
{
    /** Publishes the staged directory and preserves an existing destination on failure. */
    public static function publish(string $stagedDirectory, string $destination): void
    {
        $destinationPath = rtrim($destination, DIRECTORY_SEPARATOR . '/');
        if ($destinationPath === '') {
            $destinationPath = DIRECTORY_SEPARATOR;
        } elseif (preg_match('/^[A-Za-z]:$/D', $destinationPath) === 1) {
            $destinationPath .= DIRECTORY_SEPARATOR;
        }

        $stagingParent = realpath(dirname($stagedDirectory));
        $destinationParent = realpath(dirname($destinationPath));
        if ($stagingParent === false || $destinationParent === false
            || !FilesystemIdentity::sameFile($stagingParent, $destinationParent)) {
            throw new \\InvalidArgumentException('Staging directory and destination must share an existing parent directory.');
        }

        if (!is_dir($stagedDirectory) || FilesystemTree::isLink($stagedDirectory)) {
            throw new \\InvalidArgumentException('Staging path must be a directory and must not be a symbolic link.');
        }

        if (FilesystemTree::isLink($destinationPath)) {
            throw new \\InvalidArgumentException('Destination must not be a symbolic link or junction.');
        }

        if (file_exists($destinationPath) && !is_dir($destinationPath)) {
            throw new \\InvalidArgumentException('Existing destination must be a directory.');
        }

        if (file_exists($destinationPath) && FilesystemIdentity::sameFile($stagedDirectory, $destinationPath)) {
            throw new \\InvalidArgumentException('Staging directory and destination must be different filesystem entries.');
        }

        $resolvedDestination = realpath($destinationPath);
        if ($resolvedDestination !== false && dirname($resolvedDestination) === $resolvedDestination) {
            throw new \\InvalidArgumentException('Destination must not be a filesystem root.');
        }

        $hadDestination = file_exists($destinationPath);
        $backup = $destinationPath . '.phpmake-' . bin2hex(random_bytes(8)) . '.backup';

        if ($hadDestination && !@rename($destinationPath, $backup)) {
            throw new \\RuntimeException(sprintf("Unable to preserve existing destination '%s'.", $destinationPath));
        }

        if (!@rename($stagedDirectory, $destinationPath)) {
            if ($hadDestination && !@rename($backup, $destinationPath)) {
                throw new \\RuntimeException(sprintf(
                    "CRITICAL: Directory publication and rollback failed. The original destination is preserved at backup path '%s'; restore it manually to '%s'.",
                    $backup,
                    $destinationPath
                ));
            }

            throw new \\RuntimeException(sprintf("Unable to publish directory '%s'; the original destination was restored.", $destinationPath));
        }

        if ($hadDestination && !FilesystemTree::remove($backup)) {
            throw new \\RuntimeException(sprintf(
                "Directory published to '%s', but the previous destination could not be removed. Recovery backup retained at '%s'.",
                $destinationPath,
                $backup
            ));
        }
    }
}
