<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Diagnostics\ExceptionDiagnostics;
use PHPUnit\Framework\TestCase;

/** Tests safe formatting of exception context for debug diagnostics. */
final class ExceptionDiagnosticsTest extends TestCase
{
    /** Formats exception type and location without exposing message or stack arguments. */
    public function testFormatsExceptionContextWithoutMessageOrArguments(): void
    {
        // Arrange
        $secret = 'diagnostic-secret-value';
        $diagnostics = '';

        try {
            $this->throwWithSecret($secret);
        } catch (\RuntimeException $runtimeException) {
            // Act
            $diagnostics = ExceptionDiagnostics::format($runtimeException);
        }

        // Assert
        self::assertStringContainsString('Exception: RuntimeException', $diagnostics);
        self::assertStringContainsString('Location: ' . __FILE__ . ':', $diagnostics);
        self::assertStringContainsString('Stack trace:', $diagnostics);
        self::assertStringNotContainsString($secret, $diagnostics);
        self::assertStringNotContainsString('Sensitive exception message', $diagnostics);
    }

    /** Throws an exception whose argument and message must not enter the diagnostic trace. */
    private function throwWithSecret(string $secret): void
    {
        throw new \RuntimeException('Sensitive exception message: ' . $secret);
    }
}
