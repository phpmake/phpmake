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
     * @var array<string, array{key:string, action:?string}>
     */
    private const FLAG_OPTIONS = [
        '-h' => ['key' => 'help', 'action' => 'help'],
        '--help' => ['key' => 'help', 'action' => 'help'],
        '-v' => ['key' => 'showVersion', 'action' => 'showVersion'],
        '--version' => ['key' => 'showVersion', 'action' => 'showVersion'],
        '--diagnostics' => ['key' => 'diagnostics', 'action' => 'diagnostics'],
        '--validate-build' => ['key' => 'validateBuild', 'action' => 'validateBuild'],
        '-vb' => ['key' => 'validateBuild', 'action' => 'validateBuild'],
        '-d' => ['key' => 'debug', 'action' => null],
        '--debug' => ['key' => 'debug', 'action' => null],
        '-s' => ['key' => 'silent', 'action' => null],
        '--silent' => ['key' => 'silent', 'action' => null],
        '-nl' => ['key' => 'noLog', 'action' => null],
        '--no-log' => ['key' => 'noLog', 'action' => null],
        '--init' => ['key' => 'init', 'action' => 'init'],
    ];

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
            'init' => false,
        ];

        $selectedAction = null;
        foreach ($argv as $index => $arg) {
            if (!is_string($arg)) {
                throw new \InvalidArgumentException('Command-line arguments must be strings.');
            }

            if ($index === 0) {
                continue;
            }

            if (isset(self::FLAG_OPTIONS[$arg])) {
                $option = self::FLAG_OPTIONS[$arg];
                $action = $option['action'];
                if ($action !== null) {
                    if ($selectedAction !== null && $selectedAction !== $action) {
                        throw new \InvalidArgumentException('Conflicting command actions were specified.');
                    }

                    $selectedAction = $action;
                }

                $result[$option['key']] = true;
                continue;
            }

            if ($arg === '') {
                throw new \InvalidArgumentException('Empty command-line argument is not valid.');
            }

            if ($arg[0] !== '-') {
                if ($result['target'] !== null) {
                    throw new \InvalidArgumentException(sprintf("Error: Extra argument detected: '%s'.", $arg));
                }

                $result['target'] = $arg;
                continue;
            }

            throw new \InvalidArgumentException(sprintf("Unknown option: '%s'.", $arg));
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
        (new ConsoleOutput())->stdout(<<<HELP
            Usage: phpmake [Options] [target]

            Options:
            -h, --help              Show this help.
            -v, --version           Show version.
                --diagnostics       Show system diagnostics.
            -vb, --validate-build   Validate build.json configuration.
            -d, --debug             Enable debug logging.
            -s, --silent            Suppress all output except errors.
            -nl,--no-log            Disable file logging.
                --init              Create a sample build.json.

            [target]                 Target to execute (default: build.json's default)
            HELP
        );
    }

    /** Displays the PHPMake version. */
    public static function showVersion(): void
    {
        (new ConsoleOutput())->stdout(Config::TOOL_VERSION . PHP_EOL);
    }

    /** Displays runtime and extension diagnostics. */
    public static function showDiagnostics(): void
    {
        $lines = [
            'Diagnostics information:',
            ' PHP: ' . phpversion() . ' ' . (PHP_INT_SIZE * 8) . '-bit',
            ' OS: ' . PHP_OS,
            ' Loaded extensions:',
        ];

        foreach (get_loaded_extensions() as $extension) {
            $lines[] = '  - ' . $extension;
        }

        (new ConsoleOutput())->stdout(implode(PHP_EOL, $lines) . PHP_EOL);
    }

}
