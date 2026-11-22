<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Config;
use PHPUnit\Framework\TestCase;

/** Guards Composer installation metadata and the declared runtime baseline. */
final class ComposerPlatformConstraintTest extends TestCase
{
    /** Preserves the package identity, PHP floor, required extensions, and Composer entry points. */
    public function testComposerMetadataPreservesInstallationContract(): void
    {
        // Arrange
        $composer = $this->readComposerManifest();

        // Assert
        self::assertSame('phpmake/phpmake', $composer['name'] ?? null);
        self::assertSame('library', $composer['type'] ?? null);
        self::assertSame('^8.0', $composer['require']['php'] ?? null);
        self::assertSame('*', $composer['require']['ext-ctype'] ?? null);
        self::assertSame('*', $composer['require']['ext-json'] ?? null);
        self::assertSame('*', $composer['require']['ext-zip'] ?? null);
        self::assertSame(['PhpMake\\' => 'src/'], $composer['autoload']['psr-4'] ?? null);
        self::assertSame(['bin/phpmake.php'], $composer['bin'] ?? null);

        $binaryPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'phpmake.php';
        self::assertFileExists($binaryPath);
    }

    /** Keeps the public version constant synchronized with the release version file. */
    public function testToolVersionMatchesVersionFile(): void
    {
        // Arrange
        $versionPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'VERSION.txt';
        self::assertFileExists($versionPath);

        // Act
        $version = trim((string) file_get_contents($versionPath));

        // Assert
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        self::assertSame($version, Config::TOOL_VERSION);
    }

    /** Loads the repository Composer manifest for compatibility assertions. */
    private function readComposerManifest(): array
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
        self::assertIsArray($composer);
        return $composer;
    }
}
