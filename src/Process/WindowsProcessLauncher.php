<?php

declare(strict_types=1);

namespace PhpMake\Process;

/** Builds the Windows PowerShell Job Object launcher command. */
final class WindowsProcessLauncher
{
    /** @param list<string> $argv
     *  @return list<string>
     */
    public static function build(array $argv): array
    {
        $payload = base64_encode(json_encode($argv, JSON_THROW_ON_ERROR));
        $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
        $powerShell = $systemRoot . DIRECTORY_SEPARATOR . 'System32'
            . DIRECTORY_SEPARATOR . 'WindowsPowerShell' . DIRECTORY_SEPARATOR
            . 'v1.0' . DIRECTORY_SEPARATOR . 'powershell.exe';
        $runner = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Task' . DIRECTORY_SEPARATOR
            . 'Types' . DIRECTORY_SEPARATOR . 'WindowsJobRunner.ps1';

        return [
            $powerShell,
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $runner,
            $payload,
        ];
    }
}
