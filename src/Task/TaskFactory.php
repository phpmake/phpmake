<?php

namespace PhpMake\Task;

use PhpMake\Task\BaseTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ZipTask;
use PhpMake\Utilities\Logger;

/**
 * TaskFactory Class
 *
 * Responsible for creating task instances based on provided configuration.
 * This factory ensures tasks are instantiated correctly with their parameters
 * and logger dependencies.
 *
 * @package    PHPMake
 * @subpackage Task
 */
final class TaskFactory
{
    /**
     * @var Logger Logger instance for handling task output.
     */
    private Logger $logger;

    /**
     * Constructor
     *
     * Initializes TaskFactory with a logger instance.
     *
     * @param Logger $logger Logger instance.
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Create a task instance.
     *
     * Instantiates a task based on provided configuration.
     *
     * @param array $taskConfig Configuration for task.
     *
     * @return BaseTask Instance of task.
     *
     * @throws \Exception If task type is unknown.
     */
    public function create(array $taskConfig): BaseTask
    {
        $type = $taskConfig['type'];
        $params = $taskConfig['params'] ?? [];

        switch ($type) {
            case 'delete':
                return new DeleteTask($params, $this->logger);
            case 'create_directory':
                return new CreateDirectoryTask($params, $this->logger);
            case 'copy':
                return new CopyTask($params, $this->logger);
            case 'exec':
                return new ExecTask($params, $this->logger);
            case 'echo':
                return new EchoTask($params, $this->logger);
            case 'archive':
                return new ZipTask($params, $this->logger);
            default:
                throw new \Exception(sprintf("Unknown task type '%s' in build configuration.", $type));
        }
    }
}
