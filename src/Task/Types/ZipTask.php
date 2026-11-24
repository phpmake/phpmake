<?php

namespace PhpMake\Task\Types;

use PhpMake\Filesystem\AtomicFilePublisher;
use PhpMake\Filesystem\FilesystemIdentity;
use PhpMake\Task\BaseTask;
use ZipArchive;

/** Creates ZIP archives transactionally while rejecting symbolic links. */
final class ZipTask extends BaseTask
{
    private const MAX_ARCHIVE_FILES = 10000;

    private const MAX_ARCHIVE_ENTRIES = 20000;

    private const MAX_ARCHIVE_DEPTH = 256;

    private const MAX_ARCHIVE_FILE_BYTES = 536870912;

    private const MAX_ARCHIVE_TOTAL_BYTES = 2147483648;

    /** Creates the configured archive and preserves an existing output on failure. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $source = (string) ($this->params['source'] ?? '');
        $output = (string) ($this->params['output'] ?? '');
        $this->assertSafePath($source, 'archive source');
        $this->assertSafePath($output, 'archive output');
        if ($source === '' || $output === '' || (!file_exists($source) && !$this->isSymbolicLinkOrJunction($source))) {
            throw new \RuntimeException(sprintf("Archive source '%s' does not exist.", $source));
        }

        if ($this->isSymbolicLinkOrJunction($source)) {
            throw new \RuntimeException('Archive source cannot be a symlink.');
        }

        if ($this->isSymbolicLinkOrJunction($output)) {
            throw new \RuntimeException('Archive output cannot be a symbolic link or junction.');
        }

        $outputDir = dirname($output);
        if (!is_dir($outputDir) && !@mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf("Unable to create archive directory '%s'.", $outputDir));
        }

        $sourceReal = realpath($source);
        $outputParent = realpath($outputDir);
        if ($sourceReal === false || $outputParent === false) {
            throw new \RuntimeException('Unable to resolve archive source or output directory.');
        }

        if ($this->isSymbolicLinkOrJunction($source) || $this->isSymbolicLinkOrJunction($output)) {
            throw new \RuntimeException('Archive source and output cannot be symbolic links or junctions.');
        }

        $this->assertSafePath($source, 'archive source');
        $this->assertSafePath($output, 'archive output');
        $this->assertSafePath($sourceReal, 'archive source');
        $this->assertSafePath($outputParent, 'archive output directory');
        $outputReal = $outputParent . DIRECTORY_SEPARATOR . basename($output);
        $this->assertSafePath($outputReal, 'archive output');
        if ($this->isSymbolicLinkOrJunction($outputReal)) {
            throw new \RuntimeException('Archive output cannot be a symbolic link or junction.');
        }

        if (is_dir($outputReal)) {
            throw new \RuntimeException(sprintf("Archive output '%s' is a directory.", $outputReal));
        }

        if (FilesystemIdentity::sameFile($sourceReal, $outputReal, true)) {
            throw new \RuntimeException('Archive output must not overwrite its source.');
        }

        // Build beside the destination so the final rename stays on one volume.
        // The old archive is excluded as well as the temporary archive when the
        // destination lives inside the source tree.
        $temporaryArchive = @tempnam($outputParent, '.phpmake-archive-');
        if ($temporaryArchive === false || dirname($temporaryArchive) !== $outputParent) {
            if (is_string($temporaryArchive)) {
                @unlink($temporaryArchive);
            }

            throw new \RuntimeException(sprintf("Unable to create temporary archive in '%s'.", $outputParent));
        }

        $zip = new ZipArchive();
        $opened = $zip->open($temporaryArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            @unlink($temporaryArchive);
            throw new \RuntimeException(sprintf("Failed to create temporary ZIP file '%s' (code %s).", $temporaryArchive, (string) $opened));
        }

        $snapshots = [];
        try {
            $fileCount = 0;
            $entryCount = 0;
            $totalBytes = 0;
            $excludedPaths = [$outputReal, $temporaryArchive];
            $this->addFilesToZip(
                $zip,
                $sourceReal,
                $excludedPaths,
                $outputParent,
                $snapshots,
                $fileCount,
                $entryCount,
                $totalBytes
            );
            if (!$zip->close()) {
                throw new \RuntimeException(sprintf("Failed to finalize ZIP file '%s'.", $temporaryArchive));
            }
        } catch (\Throwable $throwable) {
            $zip->close();
            @unlink($temporaryArchive);
            throw $throwable;
        } finally {
            foreach ($snapshots as $snapshot) {
                @unlink($snapshot);
            }
        }

        AtomicFilePublisher::publish($temporaryArchive, $outputReal);

        if ($debug) {
            $this->logger->debug(sprintf("Archived '%s' to '%s'.", $source, $output));
        }

        return true;
    }

    /** Adds source files to the archive using paths relative to its root. */
    private function addFilesToZip(
        ZipArchive $zip,
        string $source,
        array &$excludedPaths,
        string $snapshotDirectory,
        array &$snapshots,
        int &$fileCount,
        int &$entryCount,
        int &$totalBytes
    ): void {
        if ($this->isSymbolicLinkOrJunction($source)) {
            throw new \RuntimeException('Archive source cannot be a symlink.');
        }

        $this->assertSafePath($source, 'archive input');
        if (is_file($source)) {
            foreach ($excludedPaths as $excludedPath) {
                if (FilesystemIdentity::sameFile($source, $excludedPath, true)) {
                    throw new \RuntimeException('Archive output must not overwrite its source.');
                }
            }

            ++$entryCount;
            $this->addArchiveFile(
                $zip,
                $source,
                basename($source),
                $snapshotDirectory,
                $excludedPaths,
                $snapshots,
                $fileCount,
                $totalBytes
            );
            return;
        }

        $root = rtrim($source, DIRECTORY_SEPARATOR);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($entryCount >= self::MAX_ARCHIVE_ENTRIES) {
                throw new \RuntimeException(sprintf('Archive exceeds the maximum of %d entries.', self::MAX_ARCHIVE_ENTRIES));
            }

            ++$entryCount;
            if ($iterator->getDepth() > self::MAX_ARCHIVE_DEPTH) {
                throw new \RuntimeException(sprintf('Archive exceeds the maximum directory depth of %d.', self::MAX_ARCHIVE_DEPTH));
            }

            $path = $file->getPathname();
            if ($file->isLink() || $this->isSymbolicLinkOrJunction($path)) {
                throw new \RuntimeException(sprintf("Refusing to archive symlink '%s'.", $path));
            }

            $this->assertSafePath($path, 'archive input');
            if (!$file->isFile()) {
                continue;
            }

            $real = $file->getRealPath();
            $excluded = false;
            foreach ($excludedPaths as $excludedPath) {
                if ($real !== false && FilesystemIdentity::sameFile($real, $excludedPath, true)) {
                    $excluded = true;
                    break;
                }
            }

            if ($real === false || $excluded) {
                continue; // Exclude the old output and temporary archive from the source tree.
            }

            $relative = ltrim(substr($real, strlen($root)), DIRECTORY_SEPARATOR);
            $this->addArchiveFile(
                $zip,
                $real,
                str_replace(DIRECTORY_SEPARATOR, '/', $relative),
                $snapshotDirectory,
                $excludedPaths,
                $snapshots,
                $fileCount,
                $totalBytes
            );
        }
    }

    /** Adds a file only when the archive's file-count and uncompressed-size budgets allow it. */
    private function addArchiveFile(
        ZipArchive $zip,
        string $source,
        string $archivePath,
        string $snapshotDirectory,
        array &$excludedPaths,
        array &$snapshots,
        int &$fileCount,
        int &$totalBytes
    ): void {
        if ($this->isSymbolicLinkOrJunction($source)) {
            throw new \RuntimeException(sprintf("Refusing to archive symlink '%s'.", $source));
        }

        $sourceStat = @lstat($source);
        if (!is_array($sourceStat) || !is_file($source)) {
            throw new \RuntimeException(sprintf("Archive input '%s' is not a regular file.", $source));
        }

        $input = @fopen($source, 'rb');
        if (!is_resource($input)) {
            throw new \RuntimeException(sprintf("Unable to open archive input '%s'.", $source));
        }

        $snapshot = null;
        $output = null;
        try {
            $openedStat = @fstat($input);
            if (!is_array($openedStat)
                || !isset($sourceStat['dev'], $sourceStat['ino'], $openedStat['dev'], $openedStat['ino'])
                || $sourceStat['dev'] !== $openedStat['dev']
                || $sourceStat['ino'] !== $openedStat['ino']) {
                throw new \RuntimeException(sprintf("Archive input '%s' changed while it was being opened.", $source));
            }

            $size = $openedStat['size'];
            if (!is_int($size) || $size < 0) {
                throw new \RuntimeException(sprintf("Unable to determine size of archive input '%s'.", $source));
            }

            if ($size > self::MAX_ARCHIVE_FILE_BYTES) {
                throw new \RuntimeException(sprintf(
                    "Archive input '%s' exceeds the per-file limit of %d bytes.",
                    $source,
                    self::MAX_ARCHIVE_FILE_BYTES
                ));
            }

            if ($fileCount >= self::MAX_ARCHIVE_FILES) {
                throw new \RuntimeException(sprintf('Archive exceeds the maximum of %d files.', self::MAX_ARCHIVE_FILES));
            }

            $remainingBytes = self::MAX_ARCHIVE_TOTAL_BYTES - $totalBytes;
            if ($size > $remainingBytes) {
                throw new \RuntimeException(sprintf(
                    'Archive exceeds the maximum uncompressed size of %d bytes.',
                    self::MAX_ARCHIVE_TOTAL_BYTES
                ));
            }

            $snapshot = @tempnam($snapshotDirectory, '.phpmake-archive-input-');
            if ($snapshot === false || dirname($snapshot) !== $snapshotDirectory) {
                if (is_string($snapshot)) {
                    @unlink($snapshot);
                }

                throw new \RuntimeException(sprintf("Unable to create archive snapshot for '%s'.", $source));
            }

            $output = @fopen($snapshot, 'wb');
            if (!is_resource($output)) {
                throw new \RuntimeException(sprintf("Unable to write archive snapshot for '%s'.", $source));
            }

            $limit = min(self::MAX_ARCHIVE_FILE_BYTES, $remainingBytes);
            $copied = 0;
            while (!feof($input)) {
                $readLength = min(1048576, $limit - $copied + 1);
                $chunk = @fread($input, $readLength);
                if ($chunk === false) {
                    throw new \RuntimeException(sprintf("Unable to read archive input '%s'.", $source));
                }

                if ($chunk === '') {
                    break;
                }

                $chunkLength = strlen($chunk);
                if ($chunkLength > $limit - $copied) {
                    throw new \RuntimeException(sprintf("Archive input '%s' changed beyond its configured size limit.", $source));
                }

                $offset = 0;
                while ($offset < $chunkLength) {
                    $written = @fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException(sprintf("Unable to write archive snapshot for '%s'.", $source));
                    }

                    $offset += $written;
                }

                $copied += $chunkLength;
            }

            $afterStat = @fstat($input);
            $currentPathStat = @lstat($source);
            if (!is_array($afterStat) || !is_array($currentPathStat)
                || !isset($currentPathStat['dev'], $currentPathStat['ino'])
                || $copied !== $size
                || $afterStat['size'] !== $openedStat['size']
                || $afterStat['mtime'] !== $openedStat['mtime']
                || $afterStat['ctime'] !== $openedStat['ctime']
                || $currentPathStat['dev'] !== $openedStat['dev']
                || $currentPathStat['ino'] !== $openedStat['ino']
                || $this->isSymbolicLinkOrJunction($source)) {
                throw new \RuntimeException(sprintf("Archive input '%s' changed while it was being snapshotted.", $source));
            }

            if (!fclose($output)) {
                $output = null;
                throw new \RuntimeException(sprintf("Unable to close archive snapshot for '%s'.", $source));
            }

            $output = null;

            if (!$zip->addFile($snapshot, $archivePath)) {
                throw new \RuntimeException(sprintf("Unable to add '%s' to archive.", $source));
            }

            $snapshots[] = $snapshot;
            $excludedPaths[] = $snapshot;
            $snapshot = null;
            ++$fileCount;
            $totalBytes += $copied;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }

            fclose($input);
            if (is_string($snapshot)) {
                @unlink($snapshot);
            }
        }
    }


    /** Returns the archive task identifier. */
    public function getType(): string
    {
        return 'archive';
    }

    /** Validates the source and archive output paths. */
    protected function validateParams(): void
    {
        if (!isset($this->params['source'], $this->params['output']) || !is_string($this->params['source']) || !is_string($this->params['output'])) {
            throw new \InvalidArgumentException("Archive task requires string 'source' and 'output' parameters.");
        }

        $this->assertSafePath($this->params['source'], 'archive source');
        $this->assertSafePath($this->params['output'], 'archive output');
    }
}
