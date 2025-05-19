<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Cli\CliParser;
use PHPUnit\Framework\TestCase;

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
}
