<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Config;
use PHPUnit\Framework\TestCase;

/** Tests the global PHPMake configuration constants. */
final class ConfigTest extends TestCase
{
    /** The tool version is exposed as a non-empty semantic version string. */
    public function testToolVersionIsSemanticVersion(): void
    {
        // Arrange
        $version = Config::TOOL_VERSION;

        // Act
        $isSemanticVersion = is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;

        // Assert
        self::assertIsString($version);
        self::assertTrue($isSemanticVersion);
    }
}
