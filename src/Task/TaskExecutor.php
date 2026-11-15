<?php

namespace PhpMake\Task;

use PhpMake\Build\BuildConfiguration;

    /** Executes the tasks belonging to targets in dependency order. */
final class TaskExecutor
{
    /** Runs each target task and returns a process-style exit code. */
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
                if (!$silent) {
                    fwrite(STDERR, sprintf("Error: Execution order references missing target '%s'.%s", (string) $targetName, PHP_EOL));
                }
                return 1;
            }
            $currentTarget = $targets[$targetName];
            if (!isset($currentTarget['tasks']) || !is_array($currentTarget['tasks'])) {
                if (!$silent) {
                    fwrite(STDERR, sprintf("Error: Target '%s' has no valid tasks array.%s", $targetName, PHP_EOL));
                }
                return 1;
            }
            if (!$silent) {
                echo sprintf('Executing target: %s%s', $targetName, PHP_EOL);
            }
            foreach ($currentTarget['tasks'] as $taskConfig) {
                if (!is_array($taskConfig)) {
                    return 1;
                }
                try {
                    $task = $taskFactory->create($taskConfig);
                } catch (\Throwable $e) {
                    if (!$silent) {
                        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                    }
                    return 1;
                }
                $taskType = $task->getType();
                if (!$silent) {
                    echo sprintf('  Running task: %s%s', $taskType, PHP_EOL);
                }
                if (!$task->execute($debug, $silent)) {
                    if (!$silent) {
                        echo "  ✗ Task failed.\n";
                    }
                    return 1;
                }
                if (!$silent) {
                    echo "  ✓ Task succeeded.\n";
                }
            }
        }
        return 0;
    }
}
