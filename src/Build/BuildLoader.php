<?php

namespace PhpMake\Build;

/**
 * BuildLoader Class
 *
 * Loads the build configuration from a JSON file and initializes
 * a BuildConfiguration instance based on the parsed data.
 *
 * @package    PHPMake
 * @subpackage Build
 */
final class BuildLoader
{
    /**
     * Load build configuration.
     *
     * Reads a JSON file and initializes a BuildConfiguration instance.
     *
     * @param string $buildFile Path to build configuration file (defaults to 'build.json').
     *
     * @return BuildConfiguration Parsed build configuration object.
     *
     * @throws \Exception If build file was not found.
     */
    public function load(string $buildFile = 'build.json'): BuildConfiguration
    {
        if (!file_exists($buildFile)) {
            throw new \Exception(sprintf("Build file '%s' not found.", $buildFile));
        }

        $rawContents = file_get_contents($buildFile);

        if ($rawContents === false) {
            throw new \Exception("Cannot read build file");
        }

        $config = json_decode($rawContents, true, 512, JSON_THROW_ON_ERROR);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON in build file: " . json_last_error_msg() . '.');
        }

        return new BuildConfiguration(
            $config['name'] ?? '',
            $config['description'] ?? '',
            $config['version'] ?? '',
            $config['targets'] ?? [],
            $config['default_target'] ?? 'default'
        );
    }
}
