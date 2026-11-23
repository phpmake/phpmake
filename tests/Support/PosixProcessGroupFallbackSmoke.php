<?php

declare(strict_types=1);

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
$childCode = 'usleep(1200000); file_put_contents($argv[1], "survived");';
$parentCode = '$pipes = []; $child = proc_open([PHP_BINARY, "-r", '
    . var_export($childCode, true)
    . ', $argv[1]], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);'
    . ' if (!is_resource($child)) { exit(9); } foreach ($pipes as $pipe) { fclose($pipe); } sleep(10);';

try {
    $task = new ExecTask([
        'command' => PHP_BINARY,
        'args' => ['-r', $parentCode, $marker],
        'timeout' => 0.2,
    ], new Logger(false, true, true));

    if ($task->execute(false, true)) {
        throw new RuntimeException('The timed-out process unexpectedly succeeded.');
    }

    usleep(1500000);
    if (is_file($marker)) {
        throw new RuntimeException('A descendant survived POSIX process-group termination without ext-posix.');
    }
} finally {
    if (is_file($marker)) {
        unlink($marker);
    }
}
