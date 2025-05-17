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
        $result = [
            'help' => false,
            'showVersion' => false,
            'diagnostics' => false,
            'validateBuild' => false,
            'target' => null,
            'debug' => false,
            'silent' => false,
            'noLog' => false,
            'init' => false
        ];

        foreach ($argv as $arg) {
            switch ($arg) {
                case '--help':
                case '-h':
                    $result['help'] = true;
                    break;
                case '--version':
                case '-v':
                    $result['showVersion'] = true;
                    break;
                case '--diagnostics':
                    $result['diagnostics'] = true;
                    break;
                case '--validate-build':
                case '-vb':
                    $result['validateBuild'] = true;
                    break;
                case '--debug':
                case '-d':
                    $result['debug'] = true;
                    break;
                case '--silent':
                case '-s':
                    $result['silent'] = true;
                    break;
                case '--no-log':
                case '-nl':
                    $result['noLog'] = true;
                    break;
                case '--init':
                    $result['init'] = true;
                    break;
                default:
                    if (file_exists($arg)) {
                        $result['buildFile'] = $arg;
                    } else {
                        $result['target'] = $arg;
                    }
            }
        }

        return $result;
    }

    /**
     * Show help information.
     *
     * Displays usage and available command-line options.
     */
    public static function showHelp(): void
    {
        echo <<<HELP
        Usage: phpmake [Options] [target]

        Options:
        -h, --help              Show this help.
        -v, --version           Show version.
            --diagnostics       Show system diagnostics.
        -vb, --validate-build   Validate build.json configuration.
        -d, --debug             Enable debug logging.
        -s, --silent            Suppress all output except errors.
        -nl,--no-log            Disable file logging.
            --init              Create a sample build.json file.

        [target]                 Target to execute (default: build.json's default)
        HELP;
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
