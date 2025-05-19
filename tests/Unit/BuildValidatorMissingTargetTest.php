<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildValidator;
use PHPUnit\Framework\TestCase;

final class BuildValidatorMissingTargetTest extends TestCase
{
    public function testInvalidDefaultTarget(): void
    {
        $config = [
            'name' => 'Test',
            'version' => '1.0.0',
            'targets' => [],
            'default_target' => 'missing',
        ];

        $validator = new BuildValidator();
        $errors = $validator->validate($config);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("Default target 'missing' does not exist", implode("\n", $errors));
    }
}
