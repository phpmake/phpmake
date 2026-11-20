<?php

declare(strict_types=1);

namespace PhpMake\Task;

use PhpMake\Build\BuildConfiguration;
use PhpMake\Cli\ConsoleOutput;

/** Executes target tasks and reports their status through the CLI output boundary. */
final class TaskExecutor
{
    private ConsoleOutput $output;

    /** Initializes the executor with an optional shared console output service. */
    public function __construct(?ConsoleOutput $output = null)
    {
        $this->output = $output ?? new ConsoleOutput();
    }

    /**
     * Runs each target task and returns a process-style exit code.
     *
     * @param list<string> $executionOrder
     */
    public function executeTasks(
        BuildConfiguration $config,
        array $executionOrder,
        TaskFactory $taskFactory,
        bool $debug,
        bool $silent
    ): int {
        $targets = $config->getTargets();
        foreach ($executionOrder as $targetName) {
            if (!is_string($targetName) || !array_key_exists($targetName, $targets) || !is_array($targets[$targetName])) {
                $this->output->error(sprintf("Error: Execution order references missing target '%s'.", (string) $targetName));
                return 1;
            }

            $currentTarget = $targets[$targetName];
            if (!isset($currentTarget['tasks']) || !is_array($currentTarget['tasks'])) {
                $this->output->error(sprintf("Error: Target '%s' has no valid tasks array.", $targetName));
                return 1;
            }

            if (!$silent) {
                $this->output->info(sprintf('Executing target: %s', $targetName));
            }

            foreach ($currentTarget['tasks'] as $taskConfig) {
                if (!is_array($taskConfig)) {
                    $this->output->error('Error: Task configuration must be an object.');
                    return 1;
                }

                try {
                    $task = $taskFactory->create($taskConfig);
                } catch (\Throwable $exception) {
                    $this->output->error('Error: ' . $exception->getMessage());
                    return 1;
                }

                $taskType = $task->getType();
                if (!$silent) {
                    $this->output->info(sprintf('  Running task: %s', $taskType));
                }

                if (!$task->execute($debug, $silent)) {
                    $this->output->error(sprintf("Error: Task '%s' failed.", $taskType));
                    if (!$silent) {
                        $this->output->error('  ✗ Task failed.');
                    }

                    return 1;
                }

                if (!$silent) {
                    $this->output->success('  ✓ Task succeeded.');
                }
            }
        }

        return 0;
    }
}
