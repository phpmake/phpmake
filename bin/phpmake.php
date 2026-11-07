#!/usr/bin/env php
<?php

/**
 * PHPMake CLI Build Script
 *
 * This script serves as the entry point for PHPMake's CLI tool,
 * handling command-line arguments and executing build tasks.
 *
 * @package    PHPMake
 * @subpackage bin
 * @author     Yousha Aleayoub <phpmake.github@gmail.com>
 * @license    GPL-3.0
 * @link       https://github.com/phpmake/phpmake
 *
 * @throws \Exception If an error occurs during execution.
 */

// Support both development (package root) and Composer-installed layouts.
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php', // development: package/vendor/autoload.php
    __DIR__ . '/../../autoload.php',     // installed:   vendor/autoload.php
];

$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "Error: Unable to locate Composer autoloader.\n");
    exit(1);
}

require $autoload;

use PhpMake\Utilities\Logger;
use PhpMake\Cli\CliParser;
use PhpMake\Build\BuildLoader;
use PhpMake\Task\TaskExecutor;
use PhpMake\Build\BuildExecutor;
use PhpMake\Task\TaskFactory;
use PhpMake\Build\BuildValidator;
use PhpMake\Target\DependencyResolver;

try {
    $parser = new CliParser();
    $args = $parser->parse($argv);

    // Handle version flag.
    if ($args['showVersion']) {
        CliParser::showVersion();
        exit(0);
    }

    // Handle help flag.
    if ($args['help']) {
        CliParser::showHelp();
        exit(0);
    }

    // Handle diagnostics flag.
    if ($args['diagnostics']) {
        CliParser::showDiagnostics();
        exit(0);
    }

    // Handle --init early (must not require an existing build.json).
    if ($args['init']) {
        // Prefer sample shipped with the package.
        $sampleCandidates = [
            __DIR__ . '/../build.json.example', // development / installed package
            getcwd() . '/build.json.example',  // fallback: current directory
        ];

        $sampleFile = null;
        foreach ($sampleCandidates as $candidate) {
            if (file_exists($candidate)) {
                $sampleFile = $candidate;
                break;
            }
        }

        $destFile = getcwd() . DIRECTORY_SEPARATOR . 'build.json';

        if (file_exists($destFile)) {
            fwrite(STDERR, "Error: '{$destFile}' already exists.\n");
            exit(1);
        }

        if ($sampleFile === null) {
            fwrite(STDERR, "Error: Sample config 'build.json.example' not found.\n");
            exit(1);
        }

        if (copy($sampleFile, $destFile)) {
            echo "Sample build.json created successfully.\n";
            exit(0);
        }

        fwrite(STDERR, "Error: Failed to create build.json.\n");
        exit(1);
    }

    // Handle validation flag.
    if ($args['validateBuild']) {
        if (!file_exists('build.json')) {
            fwrite(STDERR, "Error: Build file 'build.json' not found.\n");
            exit(1);
        }
        $rawConfig = json_decode(file_get_contents('build.json'), true);
        $validator = new BuildValidator();
        $errors = $validator->validate($rawConfig);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo "Validation Error: {$error}.\n";
            }
            exit(1);
        } else {
            echo "Build configuration is valid.\n";
            exit(0);
        }
    }

    // Proceed with normal execution.
    $logger = new Logger(
        $args['debug'],
        $args['silent'],
        $args['noLog']
    );
    $loader = new BuildLoader();
    $config = $loader->load();

    if (!$args['silent']) {
        $logger->info('Project: ' . $config->getName());
        $logger->info('Version: ' . $config->getVersion());
        $logger->info('Description: ' . $config->getDescription());
        $logger->info('');
    }

    $taskFactory = new TaskFactory($logger);
    $dependencyResolver = new DependencyResolver();
    $taskExecutor = new TaskExecutor();
    $executor = new BuildExecutor(
        $taskFactory,
        $dependencyResolver,
        $taskExecutor
    );
    $exitCode = $executor->execute(
        $config,
        $args['target'],
        $logger
    );

    if (!$args['silent']) {
        $exitCode === 0
            ? $logger->info('Build completed successfully.')
            : $logger->error('Build failed.');
    }

    exit($exitCode);
} catch (\Exception $exception) {
    echo $exception->getMessage();
    exit(1);
}
