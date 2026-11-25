<?php

declare(strict_types=1);
use PhpMake\Cli\ConsoleOutput;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Utilities\Logger;
require dirname(__DIR__, 2) . '/src/Cli/ConsoleOutput.php';
require dirname(__DIR__, 2) . '/src/Utilities/Logger.php';
require dirname(__DIR__, 2) . '/src/Task/TaskInterface.php';
require dirname(__DIR__, 2) . '/src/Task/BaseTask.php';
require dirname(__DIR__, 2) . '/src/PlatformSupport.php';
require dirname(__DIR__, 2) . '/src/Process/CommandLineParser.php';
require dirname(__DIR__, 2) . '/src/Process/PosixProcessLauncher.php';
require dirname(__DIR__, 2) . '/src/Process/ProcessLauncher.php';
require dirname(__DIR__, 2) . '/src/Process/ProcessSupervisor.php';
require dirname(__DIR__, 2) . '/src/Task/Types/ExecTask.php';
\PhpMake\PlatformSupport::assertSupported();
if (PHP_OS_FAMILY !== 'Linux') {
    exit(0);
}
if (function_exists('posix_setsid') || function_exists('posix_kill')) {
    fwrite(STDERR, "Run this smoke test with PHP extensions disabled using php -n.\n");
    exit(2);
}
$marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-no-posix-child-' . bin2hex(random_bytes(8));
$readyFile = $marker . '.ready';
$childCode = 'file_put_contents($argv[2], "ready"); usleep(1200000); file_put_contents($argv[1], "survived");';
$parentCode = '$pipes = []; $child = proc_open([PHP_BINARY, "-r", '
    . var_export($childCode, true)
    . ', $argv[1], $argv[2]], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);'
    . ' if (!is_resource($child)) { exit(9); } foreach ($pipes as $pipe) { fclose($pipe); }'
    . ' $deadline = microtime(true) + 3; while (!is_file($argv[2]) && microtime(true) < $deadline) { usleep(10000); }'
    . ' if (!is_file($argv[2])) { exit(10); } sleep(10);';
$errorOutput = fopen('php://temp', 'w+');
if (!is_resource($errorOutput)) {
    throw new RuntimeException('Unable to create temporary smoke-test output stream.');
}
try {
    $task = new ExecTask([
        'command' => PHP_BINARY,
        'args' => ['-r', $parentCode, $marker, $readyFile],
        'timeout' => 0.2,
    ], new Logger(false, true, true, 'build.log', new ConsoleOutput($errorOutput)));
    if ($task->execute(false, true)) {
        throw new RuntimeException('The timed-out process unexpectedly succeeded.');
    }
    usleep(1500000);
    if (is_file($marker)) {
        throw new RuntimeException('A descendant survived POSIX process-group termination without ext-posix.');
    }
} finally {
    fclose($errorOutput);
    foreach ([$marker, $readyFile] as $path) {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException(sprintf('Unable to remove smoke-test marker "%s".', $path));
        }
    }
}
