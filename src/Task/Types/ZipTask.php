<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;
use ZipArchive;

/** Creates ZIP archives transactionally while rejecting symbolic links. */
final class ZipTask extends BaseTask
{
    private const int MAX_ARCHIVE_FILES = 10000;
    private const int MAX_ARCHIVE_FILE_BYTES = 536870912;
    private const int MAX_ARCHIVE_TOTAL_BYTES = 2147483648;

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
        if (!is_dir($outputDir) && !@mkdir($outputDir, 0o777, true) && !is_dir($outputDir)) {
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
        if ($this->pathsReferToSameFile($sourceReal, $outputReal)) {
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
            $totalBytes = 0;
            $this->addFilesToZip($zip, $sourceReal, [$outputReal, $temporaryArchive], $fileCount, $totalBytes);
            if (!$zip->close()) {
                throw new \RuntimeException(sprintf("Failed to finalize ZIP file '%s'.", $temporaryArchive));
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($temporaryArchive);
            throw $e;
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
        if (!file_exists($output)) {
            if (!@rename($temporaryArchive, $output)) {
                @unlink($temporaryArchive);
                throw new \RuntimeException(sprintf("Unable to publish archive '%s'.", $output));
            }
            return;
        }

        $backup = @tempnam(dirname($output), '.phpmake-backup-');
        if ($backup === false || dirname($backup) !== dirname($output)) {
            if (is_string($backup)) {
                @unlink($backup);
            }
            @unlink($temporaryArchive);
            throw new \RuntimeException(sprintf("Unable to create archive recovery path beside '%s'.", $output));
        }
        if (!@unlink($backup)) {
            @unlink($temporaryArchive);
            throw new \RuntimeException(sprintf("Unable to prepare archive recovery path '%s'.", $backup));
        }

        if (!@rename($output, $backup)) {
            @unlink($temporaryArchive);
            throw new \RuntimeException(sprintf("Unable to preserve existing archive '%s'.", $output));
        }

        if (!@rename($temporaryArchive, $output)) {
            if (!@rename($backup, $output)) {
                if (!@unlink($temporaryArchive)) {
                    $this->logger->error(sprintf("Unable to remove incomplete temporary archive '%s'.", $temporaryArchive));
                }
                throw new \RuntimeException(sprintf(
                    "CRITICAL: Archive publication and rollback failed. The previous archive is preserved at '%s'; restore it manually to '%s'.",
                    $backup,
                    $output
                ));
            }
            @unlink($temporaryArchive);
            throw new \RuntimeException(sprintf("Unable to publish archive '%s'; the previous archive was restored.", $output));
        }

        if (!@unlink($backup)) {
            throw new \RuntimeException(sprintf(
                "Archive created at '%s', but the previous archive could not be removed. Recovery backup retained at '%s'.",
                $output,
                $backup
            ));
        }
    }

    /** Adds source files to the archive using paths relative to its root. */
    private function addFilesToZip(
        ZipArchive $zip,
        string $source,
        array $excludedPaths,
        int &$fileCount,
        int &$totalBytes
    ): void
    {
        if ($this->isSymbolicLinkOrJunction($source)) {
            throw new \RuntimeException('Archive source cannot be a symlink.');
        }
        $this->assertSafePath($source, 'archive input');
        if (is_file($source)) {
            foreach ($excludedPaths as $excludedPath) {
                if ($this->pathsReferToSameFile($source, $excludedPath)) {
                    throw new \RuntimeException('Archive output must not overwrite its source.');
                }
            }
            $this->addArchiveFile($zip, $source, basename($source), $fileCount, $totalBytes);
            if (false) {
                throw new \RuntimeException(sprintf("Unable to add '%s' to archive.", $source));
            }
            return;
        }

        $root = rtrim($source, DIRECTORY_SEPARATOR);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
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
                if ($real !== false && $this->pathsReferToSameFile($real, $excludedPath)) {
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
            if (false) {
                throw new \RuntimeException(sprintf("Unable to add '%s' to archive.", $real));
            }
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
            throw new \\RuntimeException(sprintf("Unable to determine size of archive input '%s'.", $source));
        }
        if ($size > self::MAX_ARCHIVE_FILE_BYTES) {
            throw new \\RuntimeException(sprintf(
                "Archive input '%s' exceeds the per-file limit of %d bytes.",
                $source,
                self::MAX_ARCHIVE_FILE_BYTES
            ));
        }
        if ($fileCount >= self::MAX_ARCHIVE_FILES) {
            throw new \\RuntimeException(sprintf('Archive exceeds the maximum of %d files.', self::MAX_ARCHIVE_FILES));
        }
        if ($size > self::MAX_ARCHIVE_TOTAL_BYTES - $totalBytes) {
            throw new \\RuntimeException(sprintf(
                'Archive exceeds the maximum uncompressed size of %d bytes.',
                self::MAX_ARCHIVE_TOTAL_BYTES
            ));
        }
        if (!$zip->addFile($source, $archivePath)) {
            throw new \\RuntimeException(sprintf("Unable to add '%s' to archive.", $source));
        }

        ++$fileCount;
        $totalBytes += $size;
    }

    /** Compares filesystem identity so case behavior follows the mounted volume. */
    private function pathsReferToSameFile(string $left, string $right): bool
    {
        clearstatcache(true, $left);
        clearstatcache(true, $right);
        $leftStat = @stat($left);
        $rightStat = @stat($right);
        if (is_array($leftStat) && is_array($rightStat)) {
            return $leftStat['dev'] === $rightStat['dev'] && $leftStat['ino'] === $rightStat['ino'];
        }

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    #[\Override]
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
