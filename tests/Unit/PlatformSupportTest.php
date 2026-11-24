<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\PlatformSupport;
use PHPUnit\Framework\TestCase;

/** Verifies that PHPMake supports only Windows and Linux. */
final class PlatformSupportTest extends TestCase
{
    /** Accepts only the two supported operating-system families. */
    public function testOnlyWindowsAndLinuxAreSupported(): void
    {
        // Arrange
        $supported = ['Windows', 'Linux'];
        $unsupported = ['FreeBSD', 'OpenBSD', 'SunOS'];

        // Act
        $supportedResults = array_map(
            static fn(string $family): bool => PlatformSupport::isSupportedFamily($family),
            $supported
        );
        $unsupportedResults = array_map(
            static fn(string $family): bool => PlatformSupport::isSupportedFamily($family),
            $unsupported
        );

        // Assert
        self::assertSame([true, true], $supportedResults);
        self::assertSame([false, false, false], $unsupportedResults);
    }

    /** Accepts the operating-system family used by the current supported runtime. */
    public function testAssertSupportedAcceptsCurrentOperatingSystem(): void
    {
        // Arrange
        $currentFamily = PHP_OS_FAMILY;

        // Act
        PlatformSupport::assertSupported();

        // Assert
        self::assertTrue(PlatformSupport::isSupportedFamily($currentFamily));
    }

    /** Rejects an operating-system family outside the supported matrix. */
    public function testUnsupportedOperatingSystemIsRejected(): void
    {
        // Arrange
        $family = 'FreeBSD';

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Supported operating systems are Windows and Linux.');

        // Act
        PlatformSupport::assertSupportedFamily($family);
    }
}
