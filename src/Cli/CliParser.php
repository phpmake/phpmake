<?php

namespace PhpMake\Cli;

use PhpMake\Config;

/**
 * CliParser Class
 *
 * Parses command-line arguments for PHPMake tool, handling
 * options and determining the target to execute.
 *
 * @package    PHPMake
 * @subpackage CLI
 */
final class CliParser
{
    /**
     * Parse command-line arguments.
     *
     * Extracts options, flags, and the target to execute from user input.
     *
     * @param array $argv Command-line arguments.
     *
     * @return array Associative array of parsed arguments.
     */
    public function parse(array $argv): array
    {
        $target = null;
        $showVersion = false;
        $help = false;
        $validateBuild = false;
        $debug = false;
        $silent = false;
        $noLog = false;
        $diagnostics = false;
        $args = array_slice($argv, 1); // Skip script name.

        foreach ($args as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                $help = true;
            } elseif ($arg === '-v' || $arg === '--version') {
                $showVersion = true;
            } elseif ($arg === '--validate-build') {
                $validateBuild = true;
            } elseif ($arg === '-d' || $arg === '--debug') {
                $debug = true;
            } elseif ($arg === '--no-log') {
                $noLog = true;
            } elseif ($arg === '--diagnostics') {
                $diagnostics = true;
            } elseif ($arg === '--silent') {
                $silent = true;
            } elseif ($arg[0] !== '-') { // Non-flag argument is target.
                if ($target === null) {
                    $target = $arg;
                } else {
                    fwrite(STDERR, "Error: Extra arguments detected\n");
                    exit(1);
                }
            }
        }

        return compact(
            'target',
            'showVersion',
            'help',
            'validateBuild',
            'debug',
            'silent',
            'noLog',
            'diagnostics'
        );
    }

    /**
     * Show help information.
     *
     * Displays usage and available command-line options.
     */
    public static function showHelp(): void
    {
        echo "Usage: phpmake [options] [target]\n";
        echo "Options:\n";
        echo "  -h, --help           Show this help message\n";
        echo "  -v, --version        Show tool version\n";
        echo "  -vb, --validate-build Validate build.json configuration\n";
        echo "  -d, --debug          Enable debug logging\n";
        echo "  --no-log             Disable file logging\n";
        echo "  --diagnostics        Show system diagnostics\n";
        echo "  --silent             Suppress all output except errors\n";
        echo "  [target]             Target to execute (default: build.json's default)\n";
    }

    /**
     * Show tool version.
     *
     * Displays version number of PHPMake.
     */
    public static function showVersion(): void
    {
        echo Config::TOOL_VERSION . "\n";
    }

    /**
     * Show system diagnostics.
     *
     * Displays diagnostic information such as PHP version, OS, and loaded extensions.
     */
    public static function showDiagnostics(): void
    {
        echo "Diagnostics information:\n";
        echo " PHP: " . phpversion() . " " . (PHP_INT_SIZE * 8) . "-bit\n";
        echo " OS: " . PHP_OS . "\n";
        echo " Loaded extensions:\n";

        foreach (get_loaded_extensions() as $ext) {
            echo "  - {$ext}\n";
        }
    }
}
