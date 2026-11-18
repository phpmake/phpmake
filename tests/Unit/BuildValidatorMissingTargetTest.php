<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildValidator;
use PHPUnit\Framework\TestCase;

    /** Tests validation of a default target that is not defined. */
final class BuildValidatorMissingTargetTest extends TestCase
{
    /** Reports an error when the configured default target is missing. */
    public function testInvalidDefaultTarget(): void
    {
        // Arrange
        $config = [
            'name' => 'Test',
            'version' => '2.0.1',
            'targets' => [],
            'default_target' => 'missing',
        ];

        $validator = new BuildValidator();
        // Act
        $errors = $validator->validate($config);

        // Assert
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("Default target 'missing' does not exist", implode("\n", $errors));
    }
}
