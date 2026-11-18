<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Guards the documented minimum PHP version against Composer metadata drift. */
final class ComposerPlatformConstraintTest extends TestCase
{
    /** Composer accepts PHP 8.3 and later compatible minor releases. */
    public function testPhpRequirementAllowsPhpEightThreeAndLater(): void
    {
        // Arrange
        $composerPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json';
        // Act
        $composer = json_decode(
            (string) file_get_contents($composerPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        // Assert
        self::assertSame('^8.3', $composer['require']['php'] ?? null);
    }
}
