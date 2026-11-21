<?php

declare(strict_types=1);

namespace PhpMake\\Tests\\Unit;

use PhpMake\\Cli\\CliParser;
use PHPUnit\\Framework\\TestCase;

/** Tests command-line option aliases, action conflicts, and target parsing. */
final class CliParserTest extends TestCase
{
    /** Parses the long version option. */
    public function testParsesLongVersionOption(): void
    {
        // Arrange
        $parser = new CliParser();

        // Act
        $result = $parser->parse(['script.php', '--version']);

        // Assert
        self::assertTrue($result['showVersion']);
    }

    /** Parses the short help option. */
    public function testParsesShortHelpOption(): void
    {
        // Arrange
        $parser = new CliParser();

        // Act
        $result = $parser->parse(['script.php', '-h']);

        // Assert
        self::assertTrue($result['help']);
    }

    /** Parses build validation and initialization actions. */
    public function testParsesBuildValidationAndInitializationActions(): void
    {
        // Arrange
        $parser = new CliParser();

        // Act
        $validation = $parser->parse(['script.php', '--validate-build']);
        $initialization = $parser->parse(['script.php', '--init']);

        // Assert
        self::assertTrue($validation['validateBuild']);
        self::assertTrue($initialization['init']);
    }

    /** Ensures every short/long alias pair produces the same result key. */
    public function testShortAndLongAliasesHaveEquivalentEffects(): void
    {
        // Arrange
        $parser = new CliParser();
        $aliases = [
            ['-h', '--help', 'help'],
            ['-v', '--version', 'showVersion'],
            ['-vb', '--validate-build', 'validateBuild'],
            ['-d', '--debug', 'debug'],
            ['-s', '--silent', 'silent'],
            ['-nl', '--no-log', 'noLog'],
        ];

        // Act
        $results = [];
        foreach ($aliases as [$short, $long, $key]) {
            $results[] = [
                $parser->parse(['script.php', $short]),
                $parser->parse(['script.php', $long]),
                $key,
            ];
        }

        // Assert
        foreach ($results as [$shortResult, $longResult, $key]) {
            self::assertSame($shortResult, $longResult);
            self::assertTrue($shortResult[$key]);
        }
    }

    /** Parses a target together with independent behavior flags. */
    public function testParsesTargetAndIndependentFlagsTogether(): void
    {
        // Arrange
        $parser = new CliParser();

        // Act
        $result = $parser->parse(['script.php', 'release', '--debug', '--silent', '--no-log']);

        // Assert
        self::assertSame('release', $result['target']);
        self::assertTrue($result['debug']);
        self::assertTrue($result['silent']);
        self::assertTrue($result['noLog']);
    }

    /** Allows equivalent aliases for the same action in one invocation. */
    public function testAllowsEquivalentActionAliasesTogether(): void
    {
        // Arrange
        $parser = new CliParser();

        // Act
        $result = $parser->parse(['script.php', '-h', '--help']);

        // Assert
        self::assertTrue($result['help']);
    }

    /** Prints usage and option help from the parser's option definitions. */
    public function testShowHelpPrintsRegisteredOptionsAndAliases(): void
    {
        // Arrange
        ob_start();

        // Act
        CliParser::showHelp();
        $output = ob_get_clean();

        // Assert
        self::assertIsString($output);
        self::assertStringContainsString('Usage: phpmake', $output);
        self::assertStringContainsString('-vb, --validate-build', $output);
        self::assertStringContainsString('-nl, --no-log', $output);
        self::assertStringContainsString('--silent', $output);
        self::assertStringContainsString('--init', $output);
    }

    /** Prints the configured PHPMake version. */
    public function testShowVersionPrintsConfiguredVersion(): void
    {
        // Arrange
        ob_start();

        // Act
        CliParser::showVersion();
        $output = ob_get_clean();

        // Assert
        self::assertSame(\\PhpMake\\Config::TOOL_VERSION . PHP_EOL, $output);
    }

    /** Prints runtime and extension diagnostics. */
    public function testShowDiagnosticsPrintsRuntimeInformation(): void
    {
        // Arrange
        ob_start();

        // Act
        CliParser::showDiagnostics();
        $output = ob_get_clean();

        // Assert
        self::assertIsString($output);
        self::assertStringContainsString('Diagnostics information:', $output);
        self::assertStringContainsString('PHP:', $output);
        self::assertStringContainsString('OS:', $output);
        self::assertStringContainsString('Loaded extensions:', $output);
    }

    /** Rejects empty arguments rather than indexing an empty string. */
    public function testRejectsEmptyArgument(): void
    {
        // Arrange
        $parser = new CliParser();

        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty command-line argument');

        // Act
        $parser->parse(['script.php', '']);
    }

    /** Rejects more than one positional target. */
    public function testRejectsDuplicatePositionalTargets(): void
    {
        // Arrange
        $parser = new CliParser();

        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('Extra argument detected');

        // Act
        $parser->parse(['script.php', 'build', 'test']);
    }

    /** Rejects conflicting command actions. */
    public function testRejectsConflictingActionFlags(): void
    {
        // Arrange
        $parser = new CliParser();

        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting command actions');

        // Act
        $parser->parse(['script.php', '--help', '--version']);
    }

    /** Rejects non-string command-line arguments. */
    public function testRejectsNonStringArgument(): void
    {
        // Arrange
        $parser = new CliParser();

        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('arguments must be strings');

        // Act
        $parser->parse(['script.php', 123]);
    }

    /** Rejects unrecognized command-line options. */
    public function testRejectsUnknownOption(): void
    {
        // Arrange
        $parser = new CliParser();

        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option');

        // Act
        $parser->parse(['script.php', '--unknown-option']);
    }
}
