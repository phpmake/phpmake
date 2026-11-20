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

        try {
            $fileCount = 0;
            $entryCount = 0;
            $totalBytes = 0;
            $this->addFilesToZip($zip, $sourceReal, [$outputReal, $temporaryArchive], $fileCount, $entryCount, $totalBytes);
            if (!$zip->close()) {
                throw new \RuntimeException(sprintf("Failed to finalize ZIP file '%s'.", $temporaryArchive));
            }
        } catch (\Throwable $throwable) {
            $zip->close();
            @unlink($temporaryArchive);
            throw $throwable;
        }

        $this->publishArchive($temporaryArchive, $outputReal);

        if ($debug) {
            $this->logger->debug(sprintf("Archived '%s' to '%s'.", $source, $output));
        }

        return true;
    }

    /** Publishes the completed archive and restores the previous output on failure. */
    private function publishArchive(string $temporaryArchive, string $output): void
    {
        AtomicFilePublisher::publish($temporaryArchive, $output);
    }

    /** Adds source files to the archive using paths relative to its root. */
    private function addFilesToZip(
        ZipArchive $zip,
        string $source,
        array $excludedPaths,
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
            $this->addArchiveFile($zip, $source, basename($source), $fileCount, $totalBytes);
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
        int &$fileCount,
        int &$totalBytes
    ): void {
        $size = @filesize($source);
        if ($size === false) {
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

        if ($size > self::MAX_ARCHIVE_TOTAL_BYTES - $totalBytes) {
            throw new \RuntimeException(sprintf(
                'Archive exceeds the maximum uncompressed size of %d bytes.',
                self::MAX_ARCHIVE_TOTAL_BYTES
            ));
        }

        if (!$zip->addFile($source, $archivePath)) {
            throw new \RuntimeException(sprintf("Unable to add '%s' to archive.", $source));
        }

        ++$fileCount;
        $totalBytes += $size;
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
