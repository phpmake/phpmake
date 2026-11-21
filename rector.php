<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/.',
    ])
    // Skip dependency, generated, IDE, and non-source directories.
    ->withSkip([
        __DIR__ . '/.git',
        __DIR__ . '/.github',
        __DIR__ . '/.idea',
        __DIR__ . '/.vscode',
        __DIR__ . '/.vs',
        __DIR__ . '/node_modules',
        __DIR__ . '/coverage',
        __DIR__ . '/.cache',
        __DIR__ . '/vendor',
        __DIR__ . '/__pycache__',
        __DIR__ . '/bootstrap',
        __DIR__ . '/cache',
        __DIR__ . '/packages',
        __DIR__ . '/lua_modules',
        __DIR__ . '/resources',
        __DIR__ . '/schema',
    ])
    ->withRootFiles()
    ->withIndent(' ', 4)
    ->withComposerBased()
    ->withTypeCoverageLevel(10)
    ->withDeadCodeLevel(10)
    ->withCodeQualityLevel(10)
    ->withCodingStyleLevel(10)
    // Keep generated transformations compatible with the minimum PHP version.
    ->withPhpVersion(PhpVersion::PHP_80)
    ->withPhpSets(php80: true);
