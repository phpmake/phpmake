<?php

namespace PhpMake\Build;

use PhpMake\Target\DependencyResolver;
use PhpMake\Task\TaskExecutor;
use PhpMake\Task\TaskFactory;
use PhpMake\Utilities\Logger;

/** Coordinates target resolution and task execution for a build. */
final class BuildExecutor
{
    public function __construct(
        private TaskFactory $taskFactory,
        private DependencyResolver $dependencyResolver,
        private TaskExecutor $taskExecutor
    ) {}

    /**
     * Resolves and executes the requested target and its dependencies.
     *
     * @throws \OutOfBoundsException When the requested target does not exist.
     */
    public function execute(BuildConfiguration $config, ?string $target, Logger $logger): int
    {
        $target ??= $config->getDefaultTarget();
        if (!array_key_exists($target, $config->getTargets())) {
            throw new \OutOfBoundsException(sprintf("Target '%s' not found.", $target));
        }

        return $this->taskExecutor->executeTasks(
            $config,
            $this->dependencyResolver->resolve($config, $target),
            $this->taskFactory,
            $logger->isDebugEnabled(),
            $logger->isSilent()
        );
    }
}
