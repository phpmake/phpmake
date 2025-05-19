<?php

namespace PhpMake\Task;

use PhpMake\Build\BuildConfiguration;
use PhpMake\Task\TaskFactory;

/**
 * TaskExecutor Class
 *
 * Executes a sequence of tasks based on the build configuration.
 * Handles logging, error detection, and execution flow.
 *
 * @package    PHPMake
 * @subpackage Task
 */
final class TaskExecutor
{
    /**
     * Execute tasks based on the given execution order.
     *
     * Iterates through targets and executes associated tasks while handling errors.
     *
     * @param BuildConfiguration $config        Build configuration object.
     * @param array              $executionOrder Ordered list of targets to execute.
     * @param TaskFactory        $taskFactory   Factory instance for creating tasks.
     * @param bool               $debug         Enable debug logging.
     * @param bool               $silent        Suppress output if enabled.
     *
     * @return int Exit status code (0 for success, 1 for failure).
     */
    public function executeTasks(
        BuildConfiguration $config,
        array $executionOrder,
        TaskFactory $taskFactory,
        bool $debug,
        bool $silent
    ): int {
        $success = true;

        foreach ($executionOrder as $targetName) {
            if (!$silent) {
                echo sprintf('Executing target: %s%s', $targetName, PHP_EOL);
            }

            $currentTarget = $config->getTargets()[$targetName];
            foreach ($currentTarget['tasks'] as $taskConfig) {
                $task = $taskFactory->create($taskConfig);
                $taskType = $task->getType();

                if (!$silent) {
                    echo sprintf('  Running task: %s%s', $taskType, PHP_EOL);
                }

                $result = $task->execute($debug, $silent);

                if (!$result) {
                    $success = false;
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

        return $success ? 0 : 1;
    }
}
