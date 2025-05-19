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

require __DIR__ . '/../vendor/autoload.php';

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

    if ($args['init']) {
        $sampleFile = 'build.json.example';
        $destFile = getcwd() . '/build.json';
        if (file_exists($destFile)) {
            fwrite(STDERR, "Error: '{$destFile}' already exists.\n");
            exit(1);
        }
        if (!file_exists($sampleFile)) {
            fwrite(STDERR, "Error: Sample config '{$sampleFile}' not found.\n");
            exit(1);
        }
        if (copy($sampleFile, $destFile)) {
            echo "Sample build.json created successfully.\n";
            exit(0);
        } else {
            fwrite(STDERR, "Error: Failed to create build.json.\n");
            exit(1);
        }
    }

    exit($exitCode);
} catch (\Exception $exception) {
    echo $exception->getMessage();
    exit(1);
}
