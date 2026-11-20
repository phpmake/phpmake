<?php

declare(strict_types=1);

namespace PhpMake\Filesystem;

/**
 * Publishes a completed file from a same-directory staging path with rollback.
 *
 * The caller must create the staged file beside the destination so rename
 * operations remain on the same filesystem volume.
 */
final class AtomicFilePublisher
{
    /** Publishes the staged file and restores an existing destination on failure. */
    public static function publish(string $stagedFile, string $destination): void
    {
        $directory = dirname($destination);
        if (dirname($stagedFile) !== $directory) {
            throw new \InvalidArgumentException('Staged file and destination must share a directory.');
        }

        if (!file_exists($destination)) {
            if (!@rename($stagedFile, $destination)) {
                @unlink($stagedFile);
                throw new \RuntimeException(sprintf("Unable to publish file '%s'.", $destination));
            }

            return;
        }

        $backup = @tempnam($directory, '.phpmake-backup-');
        if ($backup === false || dirname($backup) !== $directory) {
            if (is_string($backup)) {
                @unlink($backup);
            }

            @unlink($stagedFile);
            throw new \RuntimeException(sprintf("Unable to create recovery path beside '%s'.", $destination));
        }

        if (!@unlink($backup)) {
            @unlink($stagedFile);
            throw new \RuntimeException(sprintf("Unable to prepare recovery path '%s'.", $backup));
        }

        if (!@rename($destination, $backup)) {
            @unlink($stagedFile);
            throw new \RuntimeException(sprintf("Unable to preserve existing destination '%s'.", $destination));
        }

        if (!@rename($stagedFile, $destination)) {
            if (!@rename($backup, $destination)) {
                if (!@unlink($stagedFile)) {
                    throw new \RuntimeException(sprintf(
                        "CRITICAL: Publication and rollback failed. The original file is preserved at '%s'; restore it manually to '%s'.",
                        $backup,
                        $destination
                    ));
                }

                throw new \RuntimeException(sprintf(
                    "CRITICAL: Publication and rollback failed. The original file is preserved at '%s'; restore it manually to '%s'.",
                    $backup,
                    $destination
                ));
            }

            @unlink($stagedFile);
            throw new \RuntimeException(sprintf("Unable to publish file '%s'; the original was restored.", $destination));
        }

        if (!@unlink($backup)) {
            throw new \RuntimeException(sprintf(
                "File published to '%s', but the previous file could not be removed. Recovery backup retained at '%s'.",
                $destination,
                $backup
            ));
        }
    }
}
