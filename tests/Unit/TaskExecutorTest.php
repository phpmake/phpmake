<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildConfiguration;
use PhpMake\Cli\ConsoleOutput;
use PhpMake\Task\TaskExecutor;
use PhpMake\Task\TaskFactory;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests target task execution and CLI error reporting without mocks. */
final class TaskExecutorTest extends TestCase
{
    /** Executes tasks and prints progress when output is not silent. */
    public function testExecutesTasksAndPrintsProgress(): void
    {
        // Arrange
        $errors = fopen('php://temp', 'w+');
        self::assertIsResource($errors);
        $executor = new TaskExecutor(new ConsoleOutput($errors));
        $config = $this->createConfig([
            ['type' => 'echo', 'params' => ['message' => 'first']],
            ['type' => 'echo', 'params' => ['message' => 'second']],
        ]);

        // Act
        ob_start();
        try {
            $exitCode = $executor->executeTasks($config, ['build'], new TaskFactory(new Logger(false, true, true)), false, false);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            fclose($errors);
        }
        // Assert
        self::assertSame(0, $exitCode);
        self::assertIsString($output);
        self::assertStringContainsString('Executing target: build', $output);
        self::assertStringContainsString('Running task: echo', $output);
        self::assertStringContainsString('Task succeeded.', $output);
    }

    /** Rejects an execution order that references an undefined target. */
    public function testRejectsMissingTarget(): void
    {
        // Arrange
        $errors = fopen('php://temp', 'w+');
        self::assertIsResource($errors);
        $executor = new TaskExecutor(new ConsoleOutput($errors));
        $config = $this->createConfig([]);

        // Act
        $exitCode = $executor->executeTasks($config, ['missing'], new TaskFactory(new Logger(false, true, true)), false, true);
        rewind($errors);
        $message = stream_get_contents($errors);
        fclose($errors);
        // Assert
        self::assertSame(1, $exitCode);
        self::assertIsString($message);
        self::assertStringContainsString("references missing target 'missing'", $message);
    }

    /** Rejects a target whose task collection is absent. */
    public function testRejectsTargetWithoutTasksArray(): void
    {
        // Arrange
        $errors = fopen('php://temp', 'w+');
        self::assertIsResource($errors);
        $executor = new TaskExecutor(new ConsoleOutput($errors));
        $config = new BuildConfiguration('TaskExecutorTest', 'Invalid tasks', '1.0.0', ['build' => ['description' => 'missing tasks']], 'build');
        // Act
        $exitCode = $executor->executeTasks($config, ['build'], new TaskFactory(new Logger(false, true, true)), false, true);
        rewind($errors);
        $message = stream_get_contents($errors);
        fclose($errors);
        // Assert
        self::assertSame(1, $exitCode);
        self::assertIsString($message);
        self::assertStringContainsString('has no valid tasks array', $message);
    }

    /** Rejects a task entry that is not represented by an array. */
    public function testRejectsNonArrayTaskConfiguration(): void
    {
        // Arrange
        $errors = fopen('php://temp', 'w+');
        self::assertIsResource($errors);
        $executor = new TaskExecutor(new ConsoleOutput($errors));
        $config = $this->createConfig(['invalid']);

        // Act
        $exitCode = $executor->executeTasks($config, ['build'], new TaskFactory(new Logger(false, true, true)), false, true);
        rewind($errors);
        $message = stream_get_contents($errors);
        fclose($errors);
        // Assert
        self::assertSame(1, $exitCode);
        self::assertIsString($message);
        self::assertStringContainsString('Task configuration must be an object', $message);
    }

    /** Reports factory exceptions when debug mode is requested. */
    public function testReportsTaskCreationExceptionInDebugMode(): void
    {
        // Arrange
        $errors = fopen('php://temp', 'w+');
        self::assertIsResource($errors);
        $executor = new TaskExecutor(new ConsoleOutput($errors));
        $config = $this->createConfig([['type' => 'unknown', 'params' => []]]);

        // Act
        $exitCode = $executor->executeTasks($config, ['build'], new TaskFactory(new Logger(false, true, true)), true, true);
        rewind($errors);
        $message = stream_get_contents($errors);
        fclose($errors);
        // Assert
        self::assertSame(1, $exitCode);
        self::assertIsString($message);
        self::assertStringContainsString("Unknown task type 'unknown'", $message);
    }

    /** Stops execution when an external task exits unsuccessfully. */
    public function testStopsWhenTaskExecutionFails(): void
    {
        // Arrange
        $errors = fopen('php://temp', 'w+');
        self::assertIsResource($errors);
        $executor = new TaskExecutor(new ConsoleOutput($errors));
        $config = $this->createConfig([
            ['type' => 'exec', 'params' => ['command' => PHP_BINARY, 'args' => ['-r', 'exit(1);'], 'timeout' => 5]],
            ['type' => 'echo', 'params' => ['message' => 'must not run']],
        ]);

        // Act
        ob_start();
        try {
            $exitCode = $executor->executeTasks($config, ['build'], new TaskFactory(new Logger(false, true, true)), false, true);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            rewind($errors);
            $message = stream_get_contents($errors);
            fclose($errors);
        }
        // Assert
        self::assertSame(1, $exitCode);
        self::assertSame('', $output);
        self::assertIsString($message);
        self::assertStringContainsString("Task 'exec' failed", $message);
    }

    /** Writes exception diagnostics to STDERR when debug output is visible and no logger is supplied. */
    public function testReportsDiagnosticsToConsoleInVisibleDebugMode(): void
    {
        // Arrange
        $errors = fopen('php://temp', 'w+');
        self::assertIsResource($errors);
        $executor = new TaskExecutor(new ConsoleOutput($errors));
        $config = $this->createConfig([['type' => 'unknown', 'params' => []]]);

        // Act
        $exitCode = $executor->executeTasks(
            $config,
            ['build'],
            new TaskFactory(new Logger(false, true, true)),
            true,
            false
        );
        rewind($errors);
        $message = stream_get_contents($errors);
        fclose($errors);

        // Assert
        self::assertSame(1, $exitCode);
        self::assertIsString($message);
        self::assertStringContainsString('Exception: InvalidArgumentException', $message);
        self::assertStringContainsString("Unknown task type 'unknown'", $message);
    }

    /** Prints the failed-task status when a task fails in visible mode. */
    public function testPrintsFailureStatusWhenTaskFailsAndOutputIsVisible(): void
    {
        // Arrange
        $errors = fopen('php://temp', 'w+');
        self::assertIsResource($errors);
        $executor = new TaskExecutor(new ConsoleOutput($errors));
        $config = $this->createConfig([
            ['type' => 'exec', 'params' => ['command' => PHP_BINARY, 'args' => ['-r', 'exit(1);'], 'timeout' => 5]],
        ]);

        // Act
        ob_start();
        try {
            $exitCode = $executor->executeTasks(
                $config,
                ['build'],
                new TaskFactory(new Logger(false, true, true, 'build.log', new ConsoleOutput($errors))),
                false,
                false
            );
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            rewind($errors);
            $message = stream_get_contents($errors);
            fclose($errors);
        }

        // Assert
        self::assertSame(1, $exitCode);
        self::assertIsString($output);
        self::assertStringContainsString('Task failed.', $output);
        self::assertIsString($message);
        self::assertStringContainsString("Task 'exec' failed", $message);
    }

    /**
     * Creates a build configuration for the supplied task definitions.
     *
     * @param list<mixed> $tasks
     */
    private function createConfig(array $tasks): BuildConfiguration
    {
        return new BuildConfiguration('TaskExecutorTest', 'Task executor behavior', '1.0.0', ['build' => ['tasks' => $tasks]], 'build');
    }
}
