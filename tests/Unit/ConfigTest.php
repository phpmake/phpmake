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
        self::assertIsString(Config::TOOL_VERSION);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Config::TOOL_VERSION);
    }
}
