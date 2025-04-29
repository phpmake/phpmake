<?php

namespace PhpMake\Build;

use ZipArchive;

/**
 * BuildValidator Class
 *
 * Validates the build configuration to ensure all required fields
 * and targets are correctly defined.
 *
 * @package    PHPMake
 * @subpackage Build
 */
final class BuildValidator
{
    /**
     * Validate build configuration.
     *
     * Ensures required fields are present, checks target existence,
     * and validates task structure within each target.
     *
     * @param array $config The build configuration array.
     *
     * @return array List of validation errors (empty if valid).
     */
    public function validate(array $config): array
    {
        $errors = [];

        // Check required top-level fields.
        foreach (['name', 'description', 'version', 'targets', 'default_target'] as $field) {
            if (!array_key_exists($field, $config)) {
                $errors[] = "Missing required field: {$field}.";
            }
        }

        // Default target must exist.
        if (array_key_exists('default_target', $config)) {
            if (!isset($config['targets'][$config['default_target']])) {
                $errors[] = "Default target '{$config['default_target']}' does not exist.";
            }
        }

        // Validate each target.
        foreach ($config['targets'] as $targetName => $target) {
            if (!isset($target['tasks'])) {
                $errors[] = "Target '{$targetName}' has no 'tasks' array.";
            }
            foreach ($target['tasks'] as $taskIndex => $task) {
                if (!isset($task['type'])) {
                    $errors[] = "Task $taskIndex in target '{$targetName}' has no 'type'.";
                    continue;
                }
                switch ($task['type']) {
                    case 'delete':
                        if (!isset($task['params']['path'])) {
                            $errors[] = "Delete task in target '{$targetName}' missing 'path' parameter.";
                        }
                        break;
                    case 'create_directory':
                        if (!isset($task['params']['dir'])) {
                            $errors[] = "CreateDirectory task in target '{$targetName}' missing 'dir' parameter.";
                        }
                        break;
                    case 'copy':
                        if (!isset($task['params']['src']) || !isset($task['params']['dest'])) {
                            $errors[] = "Copy task in target '{$targetName}' missing 'src' or 'dest'.";
                        }
                        break;
                    case 'exec':
                        if (!isset($task['params']['command'])) {
                            $errors[] = "Exec task in target '{$targetName}' missing 'command'.";
                        }
                        break;
                    case 'echo':
                        if (!isset($task['params']['message'])) {
                            $errors[] = "Echo task in target '{$targetName}' missing 'message'.";
                        }
                        break;
                    case 'archive':
                        if (!isset($task['params']['source']) || !isset($task['params']['output'])) {
                            $errors[] = "Archive task in target '{$targetName}' missing required parameters.";
                        }
                        if (isset($task['params']['compression'])) {
                            $compression = $task['params']['compression'];
                            if (!in_array($compression, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE])) {
                                $errors[] = "Invalid compression value '{$compression}' for archive task.";
                            }
                        }
                        break;
                    default:
                        $errors[] = "Unknown task type '{$task['type']}' in target '{$targetName}'.";
                }
            }
        }

        return $errors;
    }
}
