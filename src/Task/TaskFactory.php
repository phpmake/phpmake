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
        $configuration = TaskConfiguration::fromArray($taskConfig);
        $definition = TaskDefinitionRegistry::find($configuration->getType());
        if ($definition === null) {
            throw new \InvalidArgumentException(sprintf(
                "Unknown task type '%s' in build configuration.",
                $configuration->getType()
            ));
        }

        $taskClass = $definition['class'];
        return new $taskClass($configuration->getParams(), $this->logger);
    }
}
