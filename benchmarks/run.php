<?php

declare(strict_types=1);

use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;

require dirname(__DIR__) . '/vendor/autoload.php';

$originalDirectory = getcwd();
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-benchmark-' . bin2hex(random_bytes(8));
$logger = new Logger(false, true, true);
$fileCount = 500;
$fileBytes = 4096;
$totalBytes = $fileCount * $fileBytes;

if (!mkdir($root) || !chdir($root)) {
    throw new RuntimeException('Unable to create benchmark workspace.');
}

try {
    mkdir('fixture');
    $payload = str_repeat('p', $fileBytes);

    $writeStart = hrtime(true);
    for ($index = 0; $index < $fileCount; ++$index) {
        if (file_put_contents('fixture/file-' . $index . '.bin', $payload) !== $fileBytes) {
            throw new RuntimeException('Unable to write benchmark fixture.');
        }
    }

    $writeSeconds = (hrtime(true) - $writeStart) / 1_000_000_000;

    $archiveStart = hrtime(true);
    $archiveTask = new ZipTask(['source' => 'fixture', 'output' => 'fixture.zip'], $logger);
    if (!$archiveTask->execute(false, true)) {
        throw new RuntimeException('ZIP benchmark task failed.');
    }

    $archiveSeconds = (hrtime(true) - $archiveStart) / 1_000_000_000;
    $archiveBytes = filesize('fixture.zip');

    $processSamples = [];
    for ($index = 0; $index < 10; ++$index) {
        $processStart = hrtime(true);
        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', 'usleep(1000);'],
        ], $logger);
        if (!$task->execute(false, true)) {
            throw new RuntimeException('Process-start benchmark task failed.');
        }

        $processSamples[] = (hrtime(true) - $processStart) / 1_000_000;
    }

    sort($processSamples);
    $medianProcessLatencyMs = ($processSamples[4] + $processSamples[5]) / 2;

    $autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $workerCode = <<<'PHP_WRAP'
        require $argv[1];
        $task = new \PhpMake\Task\Types\ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', 'usleep(50000);'],
        ], new \PhpMake\Utilities\Logger(false, true, true));
        exit($task->execute(false, true) ? 0 : 1);
        PHP_WRAP;
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $workers = [];
    $concurrency = 4;
    $concurrencyStart = hrtime(true);
    for ($index = 0; $index < $concurrency; ++$index) {
        $worker = proc_open(
            [PHP_BINARY, '-r', $workerCode, $autoload],
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => ['file', $nullDevice, 'w'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($worker)) {
            throw new RuntimeException('Unable to start concurrent benchmark worker.');
        }

        $workers[] = $worker;
    }

    try {
        foreach ($workers as $index => $worker) {
            $exitCode = proc_close($worker);
            $workers[$index] = null;
            if ($exitCode !== 0) {
                throw new RuntimeException('A concurrent benchmark worker failed.');
            }
        }
    } finally {
        foreach ($workers as $worker) {
            if (!is_resource($worker)) {
                continue;
            }

            $status = proc_get_status($worker);
            if ($status['running']) {
                proc_terminate($worker, 15);
                usleep(100000);
                if (proc_get_status($worker)['running']) {
                    proc_terminate($worker, 9);
                }
            }

            proc_close($worker);
        }
    }

    $concurrencySeconds = (hrtime(true) - $concurrencyStart) / 1_000_000_000;

    $result = [
        'php_version' => PHP_VERSION,
        'os_family' => PHP_OS_FAMILY,
        'fixture_files' => $fileCount,
        'fixture_bytes' => $totalBytes,
        'file_write_mib_per_second' => round(($totalBytes / 1048576) / max($writeSeconds, 0.000001), 2),
        'zip_archive_bytes' => $archiveBytes,
        'zip_mib_per_second' => round(($totalBytes / 1048576) / max($archiveSeconds, 0.000001), 2),
        'exec_process_start_median_ms' => round($medianProcessLatencyMs, 2),
        'concurrent_workers' => $concurrency,
        'concurrent_worker_wall_ms' => round($concurrencySeconds * 1000, 2),
        'parent_peak_memory_bytes' => memory_get_peak_usage(true),
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    if (is_string($originalDirectory)) {
        chdir($originalDirectory);
    }

    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($root);
    }
}
