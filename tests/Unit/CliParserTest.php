<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Cli\CliParser;
use PHPUnit\Framework\TestCase;

/** Tests parsing of command-line flags and target arguments. */
final class CliParserTest extends TestCase
{
    public function testParseVersionFlag(): void
    {
        // Arrange
        $args = ['script.php', '--version']; // Include the script name as first argument.
        $parser = new CliParser();
        // Action
        $result = $parser->parse($args);
        // Assert
        $this->assertTrue((bool) $result['showVersion']);
    }

    public function testParseHelpFlag(): void
    {
        // Arrange
        $args = ['script.php', '-h'];
        $parser = new CliParser();
        // Action
        $result = $parser->parse($args);
        // Assert
        $this->assertTrue((bool) $result['help']);
    }

    public function testParseValidateBuild(): void
    {
        // Arrange
        $args = ['script.php', '--validate-build'];
        $parser = new CliParser();
        // Action
        $result = $parser->parse($args);
        // Assert
        $this->assertTrue((bool) $result['validateBuild']);
    }

    public function testParseInitFlag(): void
    {
        // Arrange
        $args = ['script.php', '--init'];
        $parser = new CliParser();
        // Action
        $result = $parser->parse($args);
        // Assert
        $this->assertTrue((bool) $result['init']);
    }

    public function testParseTarget(): void
    {
        // Arrange
        $args = ['script.php', 'custom_target'];
        $parser = new CliParser();
        // Action
        $result = $parser->parse($args);
        // Assert
        $this->assertEquals('custom_target', $result['target']);
    }

    /** Prints the complete command-line help text. */
    public function testShowHelpPrintsUsageAndOptions(): void
    {
        // Arrange
        ob_start();
        CliParser::showHelp();
        $output = ob_get_clean();

        // Assert
        self::assertIsString($output);
        self::assertStringContainsString('Usage: phpmake', $output);
        self::assertStringContainsString('--validate-build', $output);
        self::assertStringContainsString('--silent', $output);
    }

    /** Prints the configured PHPMake version. */
    public function testShowVersionPrintsConfiguredVersion(): void
    {
        // Arrange
        ob_start();
        CliParser::showVersion();
        $output = ob_get_clean();

        // Assert
        self::assertSame(\PhpMake\Config::TOOL_VERSION . PHP_EOL, $output);
    }

    /** Prints runtime and extension diagnostics. */
    public function testShowDiagnosticsPrintsRuntimeInformation(): void
    {
        // Arrange
        ob_start();
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
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty command-line argument');
        // Act
        (new CliParser())->parse(['script.php', '']);
    }

    /** Rejects more than one positional target. */
    public function testRejectsDuplicatePositionalTargets(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Extra argument detected');
        // Act
        (new CliParser())->parse(['script.php', 'build', 'test']);
    }

    /** Rejects conflicting command actions. */
    public function testRejectsConflictingActionFlags(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting command actions');
        // Act
        (new CliParser())->parse(['script.php', '--help', '--version']);
    }

    /** Rejects non-string command-line arguments. */
    public function testRejectsNonStringArgument(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('arguments must be strings');

        // Act
        (new CliParser())->parse(['script.php', 123]);
    }

    /** Rejects unrecognized command-line options. */
    public function testRejectsUnknownOption(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option');

        // Act
        (new CliParser())->parse(['script.php', '--unknown-option']);
    }

}
