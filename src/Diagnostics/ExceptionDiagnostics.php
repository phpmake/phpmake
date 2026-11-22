<?php

declare(strict_types=1);

namespace PhpMake\Diagnostics;

/** Formats exception context for opt-in debug diagnostics without exposing arguments. */
final class ExceptionDiagnostics
{
    /** Returns exception type, source location, and an argument-free stack trace. */
    public static function format(\Throwable $exception): string
    {
        $trace = $exception->getTraceAsString();

        return implode(PHP_EOL, [
            'Exception: ' . $exception::class,
            sprintf('Location: %s:%d', $exception->getFile(), $exception->getLine()),
            'Stack trace:',
            $trace !== '' ? $trace : '(empty)',
        ]);
    }
}
