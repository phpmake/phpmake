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
        $unsupported = ['Darwin', 'FreeBSD', 'OpenBSD', 'SunOS'];

        // Act
        $supportedResults = array_map(PlatformSupport::isSupportedFamily(...), $supported);
        $unsupportedResults = array_map(PlatformSupport::isSupportedFamily(...), $unsupported);

        // Assert
        self::assertSame([true, true], $supportedResults);
        self::assertSame([false, false, false, false], $unsupportedResults);
    }

    /** Rejects an operating-system family outside the supported matrix. */
    public function testUnsupportedOperatingSystemIsRejected(): void
    {
        // Arrange
        $family = 'Darwin';

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Supported operating systems are Windows and Linux.');

        // Act
        PlatformSupport::assertSupportedFamily($family);
    }
}
