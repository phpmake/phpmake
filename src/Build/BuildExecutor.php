<?php

namespace PhpMake\Build;

use PhpMake\Build\BuildConfiguration;
use PhpMake\Task\TaskFactory;
use PhpMake\Utilities\Logger;
use PhpMake\Target\DependencyResolver;
use PhpMake\Task\TaskExecutor;

/**
 * BuildExecutor Class
 *
 * Handles the execution of build tasks within PHPMake tool.
 * It coordinates task creation, dependency resolution, and execution.
 *
 * @package    PHPMake
 * @subpackage Build
 */
final class BuildExecutor
{
    /**
     * @var TaskFactory Factory for creating tasks.
     */
    private TaskFactory $taskFactory;

    /**
     * @var DependencyResolver Resolves dependencies between tasks.
     */
    private DependencyResolver $dependencyResolver;

    /**
     * @var TaskExecutor Executes tasks in correct order.
     */
    private TaskExecutor $taskExecutor;

    /**
     * Constructor
     *
     * Initializes the BuildExecutor with required components.
     *
     * @param TaskFactory        $taskFactory        Factory for creating tasks.
     * @param DependencyResolver $dependencyResolver Resolves dependencies between tasks.
     * @param TaskExecutor       $taskExecutor       Executes tasks in the correct order.
     */
    public function __construct(
        TaskFactory $taskFactory,
        DependencyResolver $dependencyResolver,
        TaskExecutor $taskExecutor
    ) {
        $this->taskFactory = $taskFactory;
        $this->dependencyResolver = $dependencyResolver;
        $this->taskExecutor = $taskExecutor;
    }

    /**
     * Execute build tasks.
     *
     * Resolves dependencies and executes tasks for specified target.
     *
     * @param BuildConfiguration $config Build configuration object.
     * @param string|null        $target Target to execute (defaults to the default target).
     * @param Logger             $logger Logger instance for output handling.
     *
     * @return int Exit code (0 for success, non-zero for failure).
     *
     * @throws \Exception If the specified target is not found.
     */
    public function execute(BuildConfiguration $config, ?string $target, Logger $logger): int
    {
        $defaultTarget = $config->getDefaultTarget();
        $target = $target ?: $defaultTarget;

        if (!array_key_exists($target, $config->getTargets())) {
            throw new \Exception(sprintf("Target '%s' not found.", $target));
        }

        $executionOrder = $this->dependencyResolver->resolve($config, $target);

        // Get debug and silent modes from Logger.
        $debug = $logger->isDebugEnabled();
        $silent = $logger->isSilent();

        return $this->taskExecutor->executeTasks(
            $config,
            $executionOrder,
            $this->taskFactory,
            $debug,
            $silent
        );
    }
}
