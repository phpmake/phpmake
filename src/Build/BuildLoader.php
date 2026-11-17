<?php

namespace PhpMake\Build;

/** Loads, validates, and creates typed build configuration objects. */
final class BuildLoader
{
    /** Reads a build file, validates it, and creates its typed configuration object. */
    public function load(string $buildFile = 'build.json'): BuildConfiguration
    {
        if (!is_file($buildFile) || !is_readable($buildFile)) {
            throw new \RuntimeException(sprintf("Build file '%s' not found or unreadable.", $buildFile));
        }

        $rawContents = file_get_contents($buildFile);
        if ($rawContents === false) {
            throw new \RuntimeException(sprintf("Cannot read build file '%s'.", $buildFile));
        }

        // Decode as an object first: associative decoding cannot distinguish {} from [].
        $decoded = json_decode($rawContents, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('Build configuration must be a JSON object.');
        }

        $config = json_decode($rawContents, true, 512, JSON_THROW_ON_ERROR);
        $errors = (new BuildValidator())->validate($config);
        if ($errors !== []) {
            throw new \InvalidArgumentException(
                "Invalid build configuration:\n - " . implode("\n - ", $errors)
            );
        }

        return new BuildConfiguration(
            $config['name'],
            $config['description'],
            $config['version'],
            $config['targets'],
            $config['default_target']
        );
    }
}
