<?php

declare(strict_types=1);

namespace PhpMake\Cli;

/** Centralizes writes to the process standard output and error streams. */
final class ConsoleOutput
{
    /** @var resource */
    private $stderr;

    /** Initializes the error stream, defaulting to the process STDERR stream. */
    public function __construct($stderr = null)
    {
        if ($stderr !== null && !is_resource($stderr)) {
            throw new \InvalidArgumentException('The error output stream must be a valid resource.');
        }

        $this->stderr = $stderr ?? STDERR;
    }

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
        fwrite($this->stderr, $message . PHP_EOL);
    }

    /** Writes one successful task status line. */
    public function success(string $message): void
    {
        $this->stdout($message . PHP_EOL);
    }
}
