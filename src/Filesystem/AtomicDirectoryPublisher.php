<?php

declare(strict_types=1);

namespace PhpMake\\Filesystem;

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
        $stagingParent = realpath(dirname($stagedDirectory));
        $destinationParent = realpath(dirname($destination));
        if ($stagingParent === false || $destinationParent === false
            || !FilesystemIdentity::sameFile($stagingParent, $destinationParent)) {
            throw new \\InvalidArgumentException('Staging directory and destination must share an existing parent directory.');
        }

        if (!is_dir($stagedDirectory) || FilesystemTree::isLink($stagedDirectory)) {
            throw new \\InvalidArgumentException('Staging path must be a directory and must not be a symbolic link.');
        }

        if (FilesystemTree::isLink($destination)) {\n            throw new \\InvalidArgumentException('Destination must not be a symbolic link or junction.');\n        }\n\n        $hadDestination = file_exists($destination);
        $backup = $destination . '.phpmake-' . bin2hex(random_bytes(8)) . '.backup';

        if ($hadDestination && !@rename($destination, $backup)) {
            throw new \\RuntimeException(sprintf("Unable to preserve existing destination '%s'.", $destination));
        }

        if (!@rename($stagedDirectory, $destination)) {
            if ($hadDestination && !@rename($backup, $destination)) {
                throw new \\RuntimeException(sprintf(
                    "CRITICAL: Directory publication and rollback failed. The original destination is preserved at backup path '%s'; restore it manually to '%s'.",
                    $backup,
                    $destination
                ));
            }

            throw new \\RuntimeException(sprintf("Unable to publish directory '%s'; the original destination was restored.", $destination));
        }

        if ($hadDestination && !FilesystemTree::remove($backup)) {
            throw new \\RuntimeException(sprintf(
                "Directory published to '%s', but the previous destination could not be removed. Recovery backup retained at '%s'.",
                $destination,
                $backup
            ));
        }
    }
}
