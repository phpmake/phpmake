<?php

namespace PhpMake\Task\Types;

use PhpMake\Task\BaseTask;
use ZipArchive;

/**
 * ZipTask Class
 *
 * Implements a task for archiving files and directories into a ZIP archive
 * within PHPMake tool. Supports compression and structured directory inclusion.
 *
 * @package    PHPMake
 * @subpackage Task
 */
final class ZipTask extends BaseTask
{
    /**
     * Execute archive task.
     *
     * Creates a ZIP archive from specified source and applies compression if provided.
     *
     * @param bool $debug  Enable debug mode for detailed logging.
     * @param bool $silent Suppress output if enabled.
     *
     * @return bool Status of ZIP archive creation (true if successful, false otherwise).
     *
     * @throws \Exception If required parameters are missing or if ZIP creation fails.
     */
    protected function runTask(bool $debug, bool $silent): bool
    {
        $source = $this->params['source'] ?? '';
        $output = $this->params['output'] ?? '';
        $compressionMethod = $this->params['compression'] ?? ZipArchive::CM_STORE;

        if (empty($source) || empty($output)) {
            throw new \Exception("Missing 'source' or 'output' parameter for zip task.");
        }

        if (!file_exists($source)) {
            throw new \Exception(sprintf("Source '%s' does not exist.", $source));
        }

        $zip = new ZipArchive();

        if (!$zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new \Exception(sprintf("Failed to create ZIP file '%s'.", $output));
        }

        $this->addFilesToZip($zip, $source, $compressionMethod);
        $zip->close();

        if ($debug) {
            $this->logger->debug(sprintf("Archived '%s' to '%s'.", $source, $output));
        }

        return true;
    }

    /**
     * Recursively add files to ZIP archive.
     *
     * Traverses directories and includes files in archive.
     *
     * @param ZipArchive $zip               The ZIP archive instance.
     * @param string     $path              Source path to archive.
     * @param int        $compressionMethod Compression method (default: CM_STORE).
     * @param string     $baseDir           Base directory inside archive.
     */
    private function addFilesToZip(ZipArchive $zip, string $path, int $compressionMethod, string $baseDir = ''): void
    {
        if (is_dir($path)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                if ($file->isFile()) {
                    $basePath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    $relativePath = substr((string) $file->getPathname(), strlen($basePath));
                    $zip->addFile(
                        $file->getPathname(),
                        $baseDir . $relativePath,
                        $compressionMethod
                    );
                }
            }
        } else {
            $zip->addFile($path, basename($path), $compressionMethod);
        }
    }

    /**
     * Get task type identifier.
     *
     * Returns string representation of task type.
     *
     * @return string The task type ('archive').
     */
    #[\Override]
    public function getType(): string
    {
        return 'archive';
    }

    /**
     * Validate required task parameters.
     *
     * Ensures presence of 'source' and 'output' parameters before execution.
     *
     * @throws \Exception If required parameters are missing.
     */
    protected function validateParams()
    {
        if (!isset($this->params['source']) || !isset($this->params['output'])) {
            throw new \Exception("Missing required parameters for zip task.");
        }
    }
}
