<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Filesystem\FilesystemIdentity;
use PHPUnit\Framework\TestCase;

/** Tests filesystem identity comparisons using real temporary files. */
final class FilesystemIdentityTest extends TestCase
{
    /** Recognizes two references to the same existing file. */
    public function testSameFileReturnsTrueForIdenticalPath(): void
    {
        // Arrange
        $path = tempnam(sys_get_temp_dir(), 'phpmake-identity-');
        self::assertNotFalse($path);

        try {
            // Act
            $same = FilesystemIdentity::sameFile($path, $path);

            // Assert
            self::assertTrue($same);
        } finally {
            unlink($path);
        }
    }

    /** Distinguishes separate files on the same filesystem. */
    public function testSameFileReturnsFalseForDifferentFiles(): void
    {
        // Arrange
        $first = tempnam(sys_get_temp_dir(), 'phpmake-identity-a-');
        $second = tempnam(sys_get_temp_dir(), 'phpmake-identity-b-');
        self::assertNotFalse($first);
        self::assertNotFalse($second);

        try {
            // Act
            $same = FilesystemIdentity::sameFile($first, $second);

            // Assert
            self::assertFalse($same);
        } finally {
            unlink($first);
            unlink($second);
        }
    }
}
