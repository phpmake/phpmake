<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/bin',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/.git',
        __DIR__ . '/.github',
        __DIR__ . '/resources',
        __DIR__ . '/schema',
        __DIR__ . '/vendor',
    ])
    ->withRootFiles()
    ->withIndent(' ', 4)
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withComposerBased(phpunit: true)
    ->withPhpSets(php83: true)
    ->withTypeCoverageLevel(10)
    ->withDeadCodeLevel(10)
    ->withCodeQualityLevel(10)
    ->withCodingStyleLevel(10);
