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
}
