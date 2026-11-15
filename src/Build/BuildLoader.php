<?php

namespace PhpMake\Build;

final class BuildLoader
{
    public function load(string $buildFile = 'build.json'): BuildConfiguration
    {
        if (!is_file($buildFile) || !is_readable($buildFile)) {
            throw new \RuntimeException(sprintf("Build file '%s' not found or unreadable.", $buildFile));
        }
        $rawContents = file_get_contents($buildFile);
        if ($rawContents === false) {
            throw new \RuntimeException(sprintf("Cannot read build file '%s'.", $buildFile));
        }
        $config = json_decode($rawContents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config) || array_is_list($config)) {
            throw new \InvalidArgumentException('Build configuration must be a JSON object.');
        }
        return new BuildConfiguration(
            is_string($config['name'] ?? null) ? $config['name'] : '',
            is_string($config['description'] ?? null) ? $config['description'] : '',
            is_string($config['version'] ?? null) ? $config['version'] : '',
            is_array($config['targets'] ?? null) ? $config['targets'] : [],
            is_string($config['default_target'] ?? null) ? $config['default_target'] : 'default'
        );
    }
}
