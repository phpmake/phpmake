<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Tests\Support\TemporaryDirectoryTestHelper;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests execution results for built-in task types. */
final class TaskExecutionTest extends TestCase
{
    use TemporaryDirectoryTestHelper;

    private string $workspace;

    private string $originalWorkingDirectory;

    /** Initializes the logger shared by task execution tests. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalWorkingDirectory = (string) getcwd();
        $this->workspace = $this->createTemporaryDirectory('phpmake-task-execution-');
        self::assertTrue(chdir($this->workspace));
        $this->logger = new Logger(false, true, true);
    }

    /** Restores the working directory and removes any remaining task fixtures. */
    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        $this->removeTemporaryDirectory($this->workspace);
        parent::tearDown();
    }

    private Logger $logger;




    /** Creates the configured directory and reports successful task execution. */
    public function testCreateDirectoryTaskCreatesConfiguredDirectory(): void
    {
        // Arrange — path must stay inside the project directory.
        $tempDir = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-test-dir-' . uniqid('', true);
        $params = ['name' => $tempDir];
        // Act
        $task = new CreateDirectoryTask($params, $this->logger);
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
        $this->assertTrue(is_dir($tempDir));
        // Cleanup
        rmdir($tempDir);
    }

    /** Runs the configured external command and reports successful execution. */
    public function testExecTaskRunsConfiguredCommandSuccessfully(): void
    {
        // Arrange
        $params = ['command' => PHP_BINARY, 'args' => ['-r', 'echo "Test";']];
        $task = new ExecTask($params, $this->logger);
        // Act
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
    }

    /** Executes an echo task with the configured message. */
    public function testEchoTaskExecutesConfiguredMessageSuccessfully(): void
    {
        // Arrange
        $params = ['message' => 'Test message'];
        $task = new EchoTask($params, $this->logger);
        // Act
        $result = $task->execute(false, true);
        // Assert
        $this->assertTrue($result);
    }

    /** Returns failure when a regular file blocks directory creation. */
    public function testCreateDirectoryTaskReportsFilesystemFailure(): void
    {
        // Arrange
        $parentFile = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-parent-' . bin2hex(random_bytes(6));
        file_put_contents($parentFile, 'not a directory');
        $task = new CreateDirectoryTask(['name' => $parentFile . DIRECTORY_SEPARATOR . 'child'], $this->logger);
        try {
            // Assert
            // Act
            self::assertFalse($task->execute(false, true));
        } finally {
            unlink($parentFile);
        }
    }

    /** Rejects an exec timeout above the supported maximum. */
    public function testExecTaskRejectsTimeoutAboveMaximum(): void
    {
        // Arrange
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 86400');
        // Act
        new ExecTask(['command' => PHP_BINARY, 'timeout' => 86401], $this->logger);
    }



    /** Successful task execution produces no console output in silent mode. */
    public function testTaskExecutorSuppressesAllSuccessfulTaskOutputInSilentMode(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'SilentTest',
            'Silent output contract',
            '1.0.0',
            ['build' => ['tasks' => [[
                'type' => 'echo',
                'params' => ['message' => 'This must remain hidden'],
            ]]]],
            'build'
        );
        $taskFactory = new \PhpMake\Task\TaskFactory($this->logger);
        $executor = new \PhpMake\Task\TaskExecutor();

        ob_start();
        // Act
        $exitCode = $executor->executeTasks($config, ['build'], $taskFactory, false, true);
        $output = ob_get_clean();

        // Assert
        self::assertIsString($output);
        self::assertSame(0, $exitCode);
        self::assertSame('', $output);
    }



    /** Invalid execution-order targets stop execution with a failure code. */
    public function testTaskExecutorRejectsMissingExecutionTarget(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'InvalidOrder',
            'Missing target',
            '1.0.0',
            ['build' => ['tasks' => []]],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['missing'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }

    /** Targets without a task list fail instead of being silently skipped. */
    public function testTaskExecutorRejectsMalformedTaskList(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'MalformedTasks',
            'Malformed task list',
            '1.0.0',
            ['build' => ['tasks' => 'not-an-array']],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['build'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }

    /** Debug mode records diagnostics when task construction throws before execution. */
    public function testTaskExecutorLogsFactoryExceptionDiagnostics(): void
    {
        // Arrange
        $logFile = getcwd() . DIRECTORY_SEPARATOR . '.phpmake-task-diagnostics-' . bin2hex(random_bytes(6)) . '.log';
        $logger = new Logger(true, true, false, $logFile);
        $config = new \PhpMake\Build\BuildConfiguration(
            'InvalidTask',
            'Invalid task configuration',
            '1.0.0',
            ['build' => ['tasks' => [['type' => '', 'params' => []]]]],
            'build'
        );

        try {
            // Act
            $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
                $config,
                ['build'],
                new \PhpMake\Task\TaskFactory($logger),
                true,
                true,
                $logger
            );

            // Assert
            $log = file_get_contents($logFile);
            self::assertSame(1, $exitCode);
            self::assertIsString($log);
            self::assertStringContainsString('Exception: ', $log);
            self::assertStringContainsString('Stack trace:', $log);
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }

            if (is_file($logFile . '.lock')) {
                unlink($logFile . '.lock');
            }
        }
    }

    /** Invalid task configuration is caught and reported as a failed build. */
    public function testTaskExecutorHandlesTaskFactoryFailure(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'InvalidTask',
            'Invalid task configuration',
            '1.0.0',
            ['build' => ['tasks' => [['type' => '', 'params' => []]]]],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['build'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }

    /** A task returning false stops the target and returns a failure code. */
    public function testTaskExecutorStopsAfterTaskFailure(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'FailingTask',
            'Non-zero process exit',
            '1.0.0',
            ['build' => ['tasks' => [[
                'type' => 'exec',
                'params' => ['command' => PHP_BINARY, 'args' => ['-r', 'exit(7);']],
            ]]]],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['build'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }


    /** Non-array task entries fail safely without invoking the task factory. */
    public function testTaskExecutorRejectsNonArrayTaskEntry(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'MalformedTaskEntry',
            'Task entry is not an object',
            '1.0.0',
            ['build' => ['tasks' => ['invalid-task-entry']]],
            'build'
        );

        // Act
        $exitCode = (new \PhpMake\Task\TaskExecutor())->executeTasks(
            $config,
            ['build'],
            new \PhpMake\Task\TaskFactory($this->logger),
            false,
            true
        );

        // Assert
        self::assertSame(1, $exitCode);
    }


    /** Rejects an echo task when its required message parameter is absent. */
    public function testEchoTaskRequiresMessageParameter(): void
    {
        // Arrange
        $params = [];

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing 'message' parameter for echo task.");

        // Act
        new EchoTask($params, $this->logger);
    }

    /** Allows an explicitly empty echo message. */
    public function testEchoTaskAllowsEmptyMessage(): void
    {
        // Arrange
        $task = new EchoTask(['message' => ''], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertTrue($result);
    }


    /** Terminates commands that exceed the bounded output capture limit. */
    public function testExecTaskRejectsExcessiveOutput(): void
    {
        // Arrange
        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', 'echo str_repeat("x", 1100000);'],
        ], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
    }


    /** Stops a process that exceeds its configured execution timeout. */
    public function testExecTaskEnforcesTimeout(): void
    {
        // Arrange
        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', 'sleep(2);'],
            'timeout' => 0.1,
        ], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
    }

    /** Rejects unbalanced quotes before attempting to launch a process. */
    public function testExecTaskRejectsUnbalancedCommandQuotes(): void
    {
        // Arrange
        $command = PHP_BINARY . ' "unterminated';

        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unbalanced quote');

        // Act
        new ExecTask(['command' => $command], $this->logger);
    }


    /** Ensures timeout handling terminates descendants, not only the parent process. */
    public function testExecTaskTerminatesProcessTreeOnTimeout(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Windows' && (
            !function_exists('posix_setsid')
            || !function_exists('posix_kill')
        )) {
            self::markTestSkipped(
                'POSIX process-group support (posix_setsid and posix_kill) is unavailable.'
            );
        }

        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-child-survived-' . bin2hex(random_bytes(8));
        $detailsFile = $marker . '.details';
        $parentPidFile = $marker . '.parent';
        $childCode = 'file_put_contents($argv[1], "child=" . getmypid() . "; expected-parent=" . $argv[2]); '
            . '$query = "(Get-CimInstance -ClassName Win32_Process -Filter "ProcessId = " + getmypid() + "").ParentProcessId"; '
            . '$pipes = []; $probe = proc_open(["powershell.exe", "-NoProfile", "-NonInteractive", "-Command", $query], '
            . '[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes); '
            . 'if (is_resource($probe)) { fclose($pipes[0]); $actual = trim((string) stream_get_contents($pipes[1])); '
            . 'stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($probe); '
            . 'file_put_contents($argv[1], "; actual-parent=" . $actual, FILE_APPEND); } '
            . 'usleep(1600000); file_put_contents($argv[1] . ".survived", "survived");';
        $parentCode = 'file_put_contents($argv[2], (string) getmypid()); '
            . '$pipes = []; usleep(200000); $child = proc_open([PHP_BINARY, "-r", '
            . var_export($childCode, true)
            . ', $argv[1], (string) getmypid()], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);'
            . ' if (!is_resource($child)) { exit(9); } foreach ($pipes as $pipe) { fclose($pipe); } sleep(10);';

        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', $parentCode, $detailsFile, $parentPidFile],
            'timeout' => 0.7,
        ], $this->logger);

        try {
            // Act
            $result = $task->execute(false, true);
            usleep(1900000);

            // Assert
            self::assertFalse($result);
            self::assertFileDoesNotExist(
                $detailsFile . '.survived',
                sprintf(
                    'Surviving child details: %s; parent PID: %s',
                    (string) @file_get_contents($detailsFile),
                    (string) @file_get_contents($parentPidFile)
                )
            );
        } finally {
            foreach ([$marker, $detailsFile, $detailsFile . '.survived', $parentPidFile] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }


    /** Ensures a Windows Job Object kills background descendants after the command exits. */
    public function testExecTaskJobObjectKillsBackgroundDescendantsAfterParentExits(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows Job Object behavior is only available on Windows.');
        }

        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-job-object-child-' . bin2hex(random_bytes(8));
        $childCode = 'usleep(1200000); file_put_contents($argv[1], "survived");';
        $parentCode = '$pipes = []; $child = proc_open([PHP_BINARY, "-r", '
            . var_export($childCode, true)
            . ', $argv[1]], [0 => ["file", "NUL", "r"], 1 => ["file", "NUL", "w"], 2 => ["file", "NUL", "w"]], $pipes);'
            . ' if (!is_resource($child)) { exit(9); }'
            . ' foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }'
            . ' exit(0);';

        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', $parentCode, $marker],
            'timeout' => 10,
        ], $this->logger);

        try {
            // Act
            self::assertTrue($task->execute(false, true));
            usleep(1500000);

            // Assert
            self::assertFileDoesNotExist(
                $marker,
                'A background descendant must not outlive the completed exec task Job Object.'
            );
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }


    /** Terminates descendants when a process exceeds the bounded output capture limit. */
    public function testExecTaskTerminatesProcessTreeWhenOutputLimitIsExceeded(): void
    {
        // Arrange
        if (PHP_OS_FAMILY !== 'Windows' && (
            !function_exists('posix_setsid')
            || !function_exists('posix_kill')
        )) {
            self::markTestSkipped(
                'POSIX process-group support (posix_setsid and posix_kill) is unavailable.'
            );
        }

        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-output-child-survived-' . bin2hex(random_bytes(8));
        $childCode = 'usleep(1600000); file_put_contents($argv[1], "survived");';
        $parentCode = '$pipes = []; $child = proc_open([PHP_BINARY, "-r", '
            . var_export($childCode, true)
            . ', $argv[1]], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);'
            . ' if (!is_resource($child)) { exit(9); } foreach ($pipes as $pipe) { fclose($pipe); }'
            . ' echo str_repeat("x", 1100000); sleep(10);';

        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', $parentCode, $marker],
            'timeout' => 10,
        ], $this->logger);

        try {
            // Act
            $result = $task->execute(false, true);
            usleep(1900000);

            // Assert
            self::assertFalse($result);
            self::assertFileDoesNotExist($marker);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }



    /** Confirms process output is captured through bounded pipes rather than temporary disk files. */
    public function testExecTaskDoesNotSpoolOutputToTemporaryFiles(): void
    {
        // Arrange
        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-output-spool-check-' . bin2hex(random_bytes(8));
        $childCode = '$files = array_merge('
            . 'glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "phpmake-stdout-*") ?: [], '
            . 'glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "phpmake-stderr-*") ?: []); '
            . 'file_put_contents($argv[1], (string) count($files)); '
            . 'echo str_repeat("x", 1100000);';
        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', $childCode, $marker],
        ], $this->logger);

        try {
            // Act
            $result = $task->execute(false, true);

            // Assert
            self::assertFalse($result);
            self::assertSame('0', file_get_contents($marker));
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }


    /** SIGTERM cancels an active exec task and terminates its child process group. */
    public function testExecTaskHandlesPosixCancellation(): void
    {
        // Arrange
        if (
            PHP_OS_FAMILY === 'Windows'
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_async_signals')
            || !function_exists('posix_kill')
        ) {
            self::markTestSkipped('POSIX signal cancellation is not available on this platform.');
        }

        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-cancel-' . bin2hex(random_bytes(8));
        $workerPidFile = $marker . '.worker';
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $workerCode = <<<'PHP_WRAP'
            require $argv[1];
            $logger = new \PhpMake\Utilities\Logger(false, true, true);
            $task = new \PhpMake\Task\Types\ExecTask([
                'command' => PHP_BINARY,
                'args' => [
                    '-r',
                    'file_put_contents($argv[1] . ".started", "started"); usleep(1500000); file_put_contents($argv[1] . ".survived", "survived");',
                    $argv[2],
                ],
                'timeout' => 10,
            ], $logger);
            file_put_contents($argv[2] . ".worker", (string) getmypid());
            exit($task->execute(false, true) ? 0 : 1);
            PHP_WRAP;
        $process = proc_open(
            [PHP_BINARY, '-r', $workerCode, $autoload, $marker],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd(),
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        try {
            $deadline = hrtime(true) + 5_000_000_000;
            while (!is_file($marker . '.started') && hrtime(true) < $deadline) {
                usleep(10000);
            }

            self::assertFileExists($marker . '.started');
            $workerPid = (int) file_get_contents($workerPidFile);

            // Act
            self::assertTrue(posix_kill($workerPid, SIGTERM));
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }

                usleep(10000);
            } while (hrtime(true) < $deadline);

            // Assert
            self::assertFalse($status['running'], 'The cancelled worker must exit promptly.');
            self::assertFileDoesNotExist($marker . '.survived');
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }

            proc_close($process);
            foreach (['.started', '.survived', '.worker'] as $suffix) {
                if (is_file($marker . $suffix)) {
                    unlink($marker . $suffix);
                }
            }
        }
    }


    /** Terminates descendants that create a new POSIX session before the command times out. */
    public function testExecTaskTerminatesDescendantThatCreatesNewSession(): void
    {
        // Arrange
        if (
            PHP_OS_FAMILY === 'Windows'
            || !function_exists('posix_setsid')
            || !function_exists('posix_kill')
        ) {
            self::markTestSkipped('POSIX session escape behavior is not available on this platform.');
        }

        $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpmake-detached-child-' . bin2hex(random_bytes(8));
        $pidFile = $marker . '.pid';
        $childCode = 'if (!posix_setsid()) { exit(9); } '
            . 'file_put_contents($argv[1] . ".pid", (string) getmypid()); '
            . 'usleep(1200000); file_put_contents($argv[1], "survived");';
        $parentCode = '$pipes = []; $child = proc_open([PHP_BINARY, "-r", '
            . var_export($childCode, true)
            . ', $argv[1]], [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);'
            . ' if (!is_resource($child)) { exit(9); } foreach ($pipes as $pipe) { fclose($pipe); }'
            . ' $deadline = hrtime(true) + 1000000000; while (!is_file($argv[1] . ".pid") && hrtime(true) < $deadline) { usleep(10000); }'
            . ' sleep(10);';

        try {
            // Act
            $task = new ExecTask([
                'command' => PHP_BINARY,
                'args' => ['-r', $parentCode, $marker],
                'timeout' => 0.4,
            ], $this->logger);
            self::assertFalse($task->execute(false, true));
            usleep(1500000);

            // Assert
            self::assertFileDoesNotExist(
                $marker,
                'The detached descendant must be terminated even after leaving the original process group.'
            );
        } finally {
            if (is_file($pidFile)) {
                $detachedPid = (int) file_get_contents($pidFile);
                if ($detachedPid > 0) {
                    posix_kill($detachedPid, 9);
                }

                unlink($pidFile);
            }

            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    /** Reports the full successful task lifecycle when execution is not silent. */
    public function testTaskExecutorOutputsSuccessfulTaskLifecycle(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'VisibleTest',
            'Visible output contract',
            '1.0.0',
            ['build' => ['tasks' => [[
                'type' => 'echo',
                'params' => ['message' => 'visible payload'],
            ]]]],
            'build'
        );
        $executor = new \PhpMake\Task\TaskExecutor();
        $factory = new \PhpMake\Task\TaskFactory($this->logger);

        ob_start();

        // Act
        $exitCode = $executor->executeTasks($config, ['build'], $factory, false, false);
        $output = ob_get_clean();

        // Assert
        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Executing target: build', $output);
        self::assertStringContainsString('Running task: echo', $output);
        self::assertStringContainsString('Task succeeded', $output);
    }

    /** Reports a task failure in visible mode before returning a failure exit code. */
    public function testTaskExecutorOutputsTaskFailureWhenNotSilent(): void
    {
        // Arrange
        $config = new \PhpMake\Build\BuildConfiguration(
            'VisibleFailure',
            'Visible failure output',
            '1.0.0',
            ['build' => ['tasks' => [[
                'type' => 'exec',
                'params' => ['command' => PHP_BINARY, 'args' => ['-r', 'exit(7);']],
            ]]]],
            'build'
        );
        $executor = new \PhpMake\Task\TaskExecutor();
        $factory = new \PhpMake\Task\TaskFactory($this->logger);

        ob_start();

        // Act
        $exitCode = $executor->executeTasks($config, ['build'], $factory, false, false);
        $output = ob_get_clean();

        // Assert
        self::assertIsString($output);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Executing target: build', $output);
        self::assertStringContainsString('Running task: exec', $output);
        self::assertStringContainsString('Task failed', $output);
    }


    /** Rejects invalid command, argument, and timeout configuration before launch. */
    public function testExecTaskRejectsInvalidConfiguration(): void
    {
        // Arrange
        $invalidConfigurations = [
            'missing command' => [
                'params' => [],
                'message' => "Exec task requires a non-empty string 'command'.",
            ],
            'blank command' => [
                'params' => ['command' => '  '],
                'message' => "Exec task requires a non-empty string 'command'.",
            ],
            'non-scalar argument' => [
                'params' => ['command' => PHP_BINARY, 'args' => ['-r', new \stdClass()]],
                'message' => "Exec task 'args' must be an array of scalar values.",
            ],
            'string timeout' => [
                'params' => ['command' => PHP_BINARY, 'timeout' => '5'],
                'message' => "Exec task 'timeout' must be greater than 0 and at most 86400 seconds.",
            ],
            'zero timeout' => [
                'params' => ['command' => PHP_BINARY, 'timeout' => 0],
                'message' => "Exec task 'timeout' must be greater than 0 and at most 86400 seconds.",
            ],
            'infinite timeout' => [
                'params' => ['command' => PHP_BINARY, 'timeout' => INF],
                'message' => "Exec task 'timeout' must be greater than 0 and at most 86400 seconds.",
            ],
        ];

        foreach ($invalidConfigurations as $scenario => $configuration) {
            $logger = new Logger(false, true, true);

            try {
                // Act
                new ExecTask($configuration['params'], $logger);
                self::fail(sprintf('Expected invalid configuration "%s" to throw.', $scenario));
            } catch (\InvalidArgumentException $exception) {
                // Assert
                self::assertSame($configuration['message'], $exception->getMessage(), $scenario);
            }
        }
    }

    /** Captures stderr diagnostics without treating a successful process exit as failure. */
    public function testExecTaskCapturesStderrOnSuccessfulExit(): void
    {
        // Arrange
        $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpmake-exec-stderr-' . bin2hex(random_bytes(8)) . '.log';
        $logger = new Logger(false, true, false, $logFile);
        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', 'fwrite(STDERR, "stderr diagnostic");'],
        ], $logger);

        try {
            // Act
            $result = $task->execute(false, true);

            // Assert
            self::assertTrue($result);
            $log = file_get_contents($logFile);
            self::assertIsString($log);
            self::assertStringContainsString('stderr diagnostic', $log);
        } finally {
            foreach ([$logFile, $logFile . '.lock'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** Enforces the independent output bound on stderr as well as stdout. */
    public function testExecTaskRejectsExcessiveStderrOutput(): void
    {
        // Arrange
        $task = new ExecTask([
            'command' => PHP_BINARY,
            'args' => ['-r', 'fwrite(STDERR, str_repeat("x", 1100000));'],
        ], $this->logger);

        // Act
        $result = $task->execute(false, true);

        // Assert
        self::assertFalse($result);
    }

}
