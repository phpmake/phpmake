<?php

declare(strict_types=1);

namespace PhpMake\Process;

/** Selects the platform-specific process launcher. */
final class ProcessLauncher
{
    /**
     * Prepares the argv and process-tree tracking metadata for the host platform.
     *
     * @param list<string> $argv
     * @return array{argv:list<string>, pidPath:?string, processGroupCreated:bool}
     */
    public static function prepare(array $argv): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'argv' => WindowsProcessLauncher::build($argv),
                'pidPath' => null,
                'processGroupCreated' => false,
            ];
        }

        return PosixProcessLauncher::prepare($argv);
    }
}
