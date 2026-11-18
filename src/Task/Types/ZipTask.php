<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;
use ZipArchive;

    /** Creates ZIP archives while excluding the output and rejecting symbolic links. */
final class ZipTask extends BaseTask
{
    /** Creates the configured archive and removes partial output on failure. */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $source = (string) ($this->params['source'] ?? '');
        $output = (string) ($this->params['output'] ?? '');
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
        $outputReal = $outputParent . DIRECTORY_SEPARATOR . basename($output);
        $sourcePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceReal);
        $outputPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputReal);
        if (PHP_OS_FAMILY === 'Windows') {
            $sourcePath = strtolower($sourcePath);
            $outputPath = strtolower($outputPath);
        }
        if ($sourcePath === $outputPath) {
            throw new \RuntimeException('Archive output must not overwrite its source.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($outputReal, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException(sprintf("Failed to create ZIP file '%s' (code %s).", $outputReal, (string) $opened));
        }

        try {
            $this->addFilesToZip($zip, $sourceReal, $outputReal);
            if (!$zip->close()) {
                throw new \RuntimeException(sprintf("Failed to finalize ZIP file '%s'.", $outputReal));
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($outputReal);
            throw $e;
        }

        if ($debug) {
            $this->logger->debug(sprintf("Archived '%s' to '%s'.", $source, $output));
        }
        return true;
    }

    /** Adds source files to the archive using paths relative to its root. */
    private function addFilesToZip(ZipArchive $zip, string $source, string $output): void
    {
        if (is_file($source)) {
            if ($source === $output || !$zip->addFile($source, basename($source))) {
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
            if (!$file->isFile()) {
                continue;
            }
            $real = $file->getRealPath();
            $samePath = $real !== false && (in_array(PHP_OS_FAMILY, ['Windows', 'Darwin'], true) ? strcasecmp($real, $output) === 0 : $real === $output);
            if ($real === false || $samePath) {
                continue; // Exclude the output archive, including when it is inside the source tree.
            }
            $relative = ltrim(substr($real, strlen($root)), DIRECTORY_SEPARATOR);
            if (!$zip->addFile($real, str_replace(DIRECTORY_SEPARATOR, '/', $relative))) {
                throw new \RuntimeException(sprintf("Unable to add '%s' to archive.", $real));
            }
        }
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
