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
 * @throws \Throwable If an error occurs during execution.
 */

// Composer's generated vendor/bin proxy loads the consuming project's
// autoloader before including this file. In that case, do not load a
// package-local autoloader: dependencies installed as a library do not
// contain their own vendor/autoload.php.
if (!class_exists(\Composer\Autoload\ClassLoader::class, false)) {
    // Direct execution from a source checkout.
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoload)) {
        fwrite(STDERR, "Error: Unable to locate Composer autoloader.\n");
        exit(1);
    }

    require $autoload;
}

use PhpMake\Utilities\Logger;
use PhpMake\Cli\CliParser;
use PhpMake\Build\BuildLoader;
use PhpMake\Task\TaskExecutor;
use PhpMake\Build\BuildExecutor;
use PhpMake\Task\TaskFactory;
use PhpMake\Target\DependencyResolver;
use PhpMake\Cli\ConsoleOutput;

$output = new ConsoleOutput();

try {
    $parser = new CliParser();
    $args = $parser->parse($argv);

    if ($args['showVersion']) {
        CliParser::showVersion();
        exit(0);
    }

    if ($args['help']) {
        CliParser::showHelp();
        exit(0);
    }

    if ($args['diagnostics']) {
        CliParser::showDiagnostics();
        exit(0);
    }

    if ($args['init']) {
        $sampleCandidates = [
            __DIR__ . '/../build.json.example',
            getcwd() . '/build.json.example',
        ];

        $sampleFile = null;
        foreach ($sampleCandidates as $candidate) {
            if (file_exists($candidate)) {
                $sampleFile = $candidate;
                break;
            }
        }

        $destFile = getcwd() . DIRECTORY_SEPARATOR . 'build.json';
        clearstatcache(true, $destFile);
        if (file_exists($destFile) || is_link($destFile) || @lstat($destFile) !== false) {
            $output->error("Error: '{$destFile}' already exists.");
            exit(1);
        }

        if ($sampleFile === null) {
            $output->error("Error: Sample config 'build.json.example' not found.");
            exit(1);
        }

        $source = @fopen($sampleFile, 'rb');
        if ($source === false) {
            $output->error('Error: Failed to read sample build configuration.');
            exit(1);
        }

        // Exclusive creation never follows an existing file or symbolic link.
        $destination = @fopen($destFile, 'xb');
        if ($destination === false) {
            fclose($source);
            $output->error('Error: Failed to create build.json.');
            exit(1);
        }

        $expectedSize = filesize($sampleFile);
        $copied = stream_copy_to_stream($source, $destination);
        $flushed = fflush($destination);
        fclose($source);
        fclose($destination);

        if ($copied !== false && $expectedSize !== false && $copied === $expectedSize && $flushed) {
            $output->info('Sample build.json created successfully.');
            exit(0);
        }

        @unlink($destFile);
        fwrite(STDERR, "Error: Failed to create build.json.\n");
        exit(1);
    }

    // Both validation and execution use the same loader and validation rules.
    $loader = new BuildLoader();
    $config = $loader->load();

    if ($args['validateBuild']) {
        $output->info('Build configuration is valid.');
        exit(0);
    }

    $logger = new Logger($args['debug'], $args['silent'], $args['noLog']);
    if (!$args['silent']) {
        $logger->info('Project: ' . $config->getName());
        $logger->info('Version: ' . $config->getVersion());
        $logger->info('Description: ' . $config->getDescription());
        $logger->info('');
    }

    $taskFactory = new TaskFactory($logger);
    $dependencyResolver = new DependencyResolver();
    $taskExecutor = new TaskExecutor();
    $executor = new BuildExecutor($taskFactory, $dependencyResolver, $taskExecutor);
    $exitCode = $executor->execute($config, $args['target'], $logger);

    if (!$args['silent']) {
        $exitCode === 0
            ? $logger->info('Build completed successfully.')
            : $logger->error('Build failed.');
    }

    exit($exitCode);
} catch (\Throwable $throwable) {
    $output->error($throwable->getMessage());
    exit(1);
}
