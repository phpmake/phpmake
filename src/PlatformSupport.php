<?php

declare(strict_types=1);

namespace PhpMake;

/** Defines and enforces the operating systems supported by PHPMake. */
final class PlatformSupport
{
    /** @var list<string> */
    private const SUPPORTED_FAMILIES = ['Linux', 'Windows'];

    /** Returns whether the supplied operating-system family is supported. */
    public static function isSupportedFamily(string $family): bool
    {
        return in_array($family, self::SUPPORTED_FAMILIES, true);
    }

    /** Rejects execution on operating systems outside the supported matrix. */
    public static function assertSupported(): void
    {
        self::assertSupportedFamily(PHP_OS_FAMILY);
    }

    /** Rejects an explicitly supplied unsupported operating-system family. */
    public static function assertSupportedFamily(string $family): void
    {
        if (!self::isSupportedFamily($family)) {
            throw new \RuntimeException(sprintf(
                'Unsupported operating system "%s". Supported operating systems are Windows and Linux.',
                $family
            ));
        }
    }
}
