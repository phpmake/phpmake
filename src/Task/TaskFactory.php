<?php

declare(strict_types=1);

namespace PhpMake\Task;

use PhpMake\Utilities\Logger;

/** Creates task instances from the canonical task-definition registry. */
final class TaskFactory
{
    /** Initializes the factory with the logger shared by created tasks. */
    public function __construct(private Logger $logger) {}

    /**
     * Creates a task instance for a validated or partially validated task config.
     *
     * @param array<string, mixed> $taskConfig
     */
    public function create(array $taskConfig): BaseTask
    {
        $type = $taskConfig['type'] ?? null;
        $params = $taskConfig['params'] ?? [];

        if (!is_string($type) || $type === '') {
            throw new \InvalidArgumentException("Task configuration requires a non-empty string 'type'.");
        }

        if (!is_array($params)) {
            throw new \InvalidArgumentException(sprintf("Parameters for task '%s' must be an array.", $type));
        }

        $definition = TaskDefinitionRegistry::find($type);
        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf("Unknown task type '%s' in build configuration.", $type));
        }

        $taskClass = $definition['class'];
        return new $taskClass($params, $this->logger);
    }
}
