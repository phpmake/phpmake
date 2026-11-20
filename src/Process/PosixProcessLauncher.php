<?php

declare(strict_types=1);

namespace PhpMake\Process;

/** Prepares POSIX session launchers and process-group tracking metadata. */
final class PosixProcessLauncher
{
    /**
     * @param list<string> $argv
     * @return array{argv:list<string>, pidPath:?string, processGroupCreated:bool}
     */
    public static function prepare(array $argv): array
    {
        $launchArgv = $argv;
        $hasPosixExtension = function_exists('posix_setsid') && function_exists('posix_kill');
        $setsidExecutable = null;
        $hasPosixProcessGroups = $hasPosixExtension;
        if (!$hasPosixProcessGroups) {
            $setsidExecutable = self::findExecutable('setsid');
            $hasPosixProcessGroups = $setsidExecutable !== null
                && self::findExecutable('kill') !== null;
        }

        $processPidPath = null;
        if (!$hasPosixExtension && $hasPosixProcessGroups) {
            $pidPath = tempnam(sys_get_temp_dir(), 'phpmake-pid-');
            $processPidPath = $pidPath === false ? null : $pidPath;
        }

        if ($hasPosixProcessGroups) {
            $launcher = <<<'PHP_WRAP'
                $command = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($command)) {
                    exit(126);
                }
                if (function_exists('posix_setsid') && !posix_setsid()) {
                    exit(126);
                }
                $child = proc_open(
                    $command,
                    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
                    $pipes,
                    getcwd(),
                    null,
                    ['bypass_shell' => true]
                );
                if (!is_resource($child)) {
                    exit(127);
                }
                $pidFile = $argv[2] ?? null;
                if (is_string($pidFile) && $pidFile !== '') {
                    @file_put_contents($pidFile, (string) getmypid(), LOCK_EX);
                }
                $exitCode = proc_close($child);
                exit($exitCode >= 0 ? $exitCode : 1);
                PHP_WRAP;
            $launchArgv = [
                PHP_BINARY,
                '-r',
                $launcher,
                base64_encode(json_encode($argv, JSON_THROW_ON_ERROR)),
            ];
            if (!$hasPosixExtension && $setsidExecutable !== null) {
                array_unshift($launchArgv, $setsidExecutable);
            }

            if ($processPidPath !== null) {
                $launchArgv[] = $processPidPath;
            }
        }

        return [
            'argv' => $launchArgv,
            'pidPath' => $processPidPath,
            'processGroupCreated' => $hasPosixProcessGroups,
        ];
    }

    /** Finds an executable in PATH without invoking a shell. */
    private static function findExecutable(string $name): ?string
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
