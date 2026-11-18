<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Cli\CliParser;
use PHPUnit\Framework\TestCase;

    /** Tests parsing of command-line flags and target arguments. */
final class CliParserTest extends TestCase
{
    /**
     * This method is called before the first test method in the test class is executed.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {}

    /**
     * This method is called after the last test method in the test class has been executed.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        gc_collect_cycles();
    }

    /**
     * This method is called BEFORE each test method.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Methods initialization codes.
    }

    /**
     * This method is called AFTER each test method.
     *
     * @doesNotPerformAssertions
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Methods finalization codes.
        parent::tearDown();
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
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

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
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

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
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

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
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

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
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
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty command-line argument');
        // Act
        (new CliParser())->parse(['script.php', '']);
    }

    /** Rejects more than one positional target. */
    public function testRejectsDuplicatePositionalTargets(): void
    {
        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('Extra argument detected');
        // Act
        (new CliParser())->parse(['script.php', 'build', 'test']);
    }

    /** Rejects conflicting command actions. */
    public function testRejectsConflictingActionFlags(): void
    {
        // Assert
        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('Conflicting command actions');
        // Act
        (new CliParser())->parse(['script.php', '--help', '--version']);
    }

}
