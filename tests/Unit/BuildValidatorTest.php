<?php

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildValidator;
use PHPUnit\Framework\TestCase;

final class BuildValidatorTest extends TestCase
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
    public function testValidConfig(): void
    {
        // Arrange
        $validConfig = [
            'name' => 'TestProject',
            'description' => 'Test description',
            'version' => '2.0.1',
            'targets' => [
                'clean' => [
                    'tasks' => [['type' => 'delete', 'params' => ['path' => 'test']]],
                ],
            ],
            'default_target' => 'clean',
        ];
        $validator = new BuildValidator();
        // Action
        $errors = $validator->validate($validConfig);
        // Assert
        $this->assertEmpty($errors);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testMissingField(): void
    {
        // Arrange
        $invalidConfig = [
            'name' => 'TestProject',
            'targets' => [],
            'default_target' => 'clean',
        ];
        $validator = new BuildValidator();
        // Action
        $errors = $validator->validate($invalidConfig);
        // Assert
        $this->assertContains('Missing required field: description.', $errors);
    }

    /**
     * @test
     *
     * @small
     *
     * @return void
     */
    public function testInvalidTask(): void
    {
        // Arrange
        $invalidConfig = [
            'name' => 'TestProject',
            'description' => 'Test',
            'version' => '2.0.1',
            'targets' => [
                'invalid' => [
                    'tasks' => [['type' => 'unknown', 'params' => []]],
                ],
            ],
            'default_target' => 'invalid',
        ];
        $validator = new BuildValidator();
        // Action
        $errors = $validator->validate($invalidConfig);
        // Assert
        $this->assertContains("Unknown task type 'unknown' in target 'invalid'.", $errors);
    }

    public function testGeneratedCopySourceIsAllowedAtValidationTime(): void
    {
        $config = [
            'name' => 'Test',
            'description' => 'Generated input',
            'version' => '1.0',
            'targets' => ['build' => ['tasks' => [[
                'type' => 'copy',
                'params' => ['source' => 'generated/output.bin', 'dest' => 'dist/output.bin'],
            ]]]],
            'default_target' => 'build',
        ];
        $this->assertSame([], (new BuildValidator())->validate($config));
    }

    public function testMalformedTargetsAndDependenciesAreReported(): void
    {
        $config = [
            'name' => 'Test',
            'description' => 'Malformed',
            'version' => '1.0',
            'targets' => [
                'build' => ['depends' => ['missing'], 'tasks' => 'not-an-array'],
                'bad' => 'not-an-object',
            ],
            'default_target' => 'build',
        ];
        $errors = (new BuildValidator())->validate($config);
        $this->assertStringContainsString("depends on missing", implode("\n", $errors));
        $this->assertStringContainsString("valid 'tasks' array", implode("\n", $errors));
        $this->assertStringContainsString("must be an object", implode("\n", $errors));
    }

    public function testNonArrayTargetsReturnValidationError(): void
    {
        $config = [
            'name' => 'Test',
            'description' => 'Bad targets',
            'version' => '1.0',
            'targets' => 'bad',
            'default_target' => 'default',
        ];
        $this->assertNotEmpty((new BuildValidator())->validate($config));
    }
}
