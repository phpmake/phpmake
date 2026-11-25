<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

static $rules = [
    '@PSR1' => true,
    '@PSR2' => true,
    '@PSR12' => true,
    '@PER-CS2.0' => true,
    // PHP-CS-Fixer 3.66 uses the legacy alias; keep this compatible with PHP 8.0 tooling.
    '@PHP80Migration' => true,
];

static $excludeFolders = [
    '.git/',
    '.github/',
    '.idea/',
    '.vscode/',
    '.vs/',
    'node_modules/',
    'coverage/',
    '.cache/',
    'vendor/',
    '__pycache__/',
    'bootstrap/',
    'cache/',
    'packages/',
    'lua_modules/',
    'resources/',
    'schema/',
];

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude($excludeFolders)
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRules($rules)
    ->setFinder($finder);
