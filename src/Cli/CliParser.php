<?php

declare(strict_types=1);

namespace PhpMake\Cli;

use PhpMake\Config;

/** Parses command-line options and identifies the requested build target. */
final class CliParser
{
    /**
     * Each option is declared once with its aliases, result key, action, and help text.
     *
     * @var list<array{aliases:list<string>, key:string, action:?string, description:string}>
     */
    private const OPTIONS = [
        ['aliases' => ['-h', '--help'], 'key' => 'help', 'action' => 'help', 'description' => 'Show this help.'],
        ['aliases' => ['-v', '--version'], 'key' => 'showVersion', 'action' => 'showVersion', 'description' => 'Show version.'],
        ['aliases' => ['--diagnostics'], 'key' => 'diagnostics', 'action' => 'diagnostics', 'description' => 'Show system diagnostics.'],
        ['aliases' => ['-vb', '--validate-build'], 'key' => 'validateBuild', 'action' => 'validateBuild', 'description' => 'Validate build.json configuration.'],
        ['aliases' => ['-d', '--debug'], 'key' => 'debug', 'action' => null, 'description' => 'Enable debug logging.'],
        ['aliases' => ['-s', '--silent'], 'key' => 'silent', 'action' => null, 'description' => 'Suppress all output except errors.'],
        ['aliases' => ['-nl', '--no-log'], 'key' => 'noLog', 'action' => null, 'description' => 'Disable file logging.'],
        ['aliases' => ['--init'], 'key' => 'init', 'action' => 'init', 'description' => 'Create a sample build.json.'],
    ];

    /**
     * Parses options, flags, and the target to execute.
     *
     * @param array<int, mixed> $argv Command-line arguments, including the script name at index zero.
     * @return array{help:bool, showVersion:bool, diagnostics:bool, validateBuild:bool, target:?string, debug:bool, silent:bool, noLog:bool, init:bool}
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

            $option = null;
            foreach (self::OPTIONS as $definition) {
                if (in_array($arg, $definition['aliases'], true)) {
                    $option = $definition;
                    break;
                }
            }

            if ($option !== null) {
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

    /** Displays help generated from the same option definitions used by the parser. */
    public static function showHelp(): void
    {
        $lines = [
            'Usage: phpmake [Options] [target]',
            '',
            'Options:',
        ];

        foreach (self::OPTIONS as $option) {
            $lines[] = sprintf(
                '  %-22s %s',
                implode(', ', $option['aliases']),
                $option['description']
            );
        }

        $lines[] = '';
        $lines[] = "[target]                 Target to execute (default: build.json's default)";
        $lines[] = '';
        $lines[] = 'Build file: build.json in the current working directory.';
        $lines[] = 'Examples:';
        $lines[] = '  phpmake --init';
        $lines[] = '  phpmake --validate-build';
        $lines[] = '  phpmake';
        $lines[] = '  phpmake build --debug';
        $lines[] = '  phpmake --diagnostics';

        (new ConsoleOutput())->stdout(implode(PHP_EOL, $lines) . PHP_EOL);
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
