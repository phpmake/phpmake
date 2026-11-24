<?php

declare(strict_types=1);

namespace PhpMake\\Cli;

/** Centralizes writes to the process standard output and error streams. */
final class ConsoleOutput
{
    /** Writes a message to standard output. */
    public function stdout(string $message): void
    {
        echo $message;
    }

    /** Writes one informational line. */
    public function info(string $message): void
    {
        $this->stdout($message . PHP_EOL);
    }

    /** Writes one error line to standard error. */
    public function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }

    /** Writes one successful task status line. */
    public function success(string $message): void
    {
        $this->stdout($message . PHP_EOL);
    }
}
