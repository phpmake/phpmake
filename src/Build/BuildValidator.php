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
                $errors[] = sprintf('Missing required field: %s.', $field);
            }
        }

        // Default target must exist.
        if (array_key_exists('default_target', $config)) {
            if (!isset($config['targets'][$config['default_target']])) {
                $errors[] = sprintf("Default target '%s' does not exist.", $config['default_target']);
            }
        }

        // Validate each target.
        foreach ($config['targets'] as $targetName => $target) {
            if (!isset($target['tasks'])) {
                $errors[] = sprintf("Target '%s' has no 'tasks' array.", $targetName);
            }

            foreach ($target['tasks'] as $taskIndex => $task) {
                if (!isset($task['type'])) {
                    $errors[] = sprintf("Task %s in target '%s' has no 'type'.", $taskIndex, $targetName);
                    continue;
                }

                switch ($task['type']) {
                    case 'delete':
                        if (!isset($task['params']['path'])) {
                            $errors[] = sprintf("Delete task in target '%s' missing 'path' parameter.", $targetName);
                        }

                        break;
                    case 'create_directory':
                        if (!isset($task['params']['name'])) {
                            $errors[] = sprintf("CreateDirectory task in target '%s' missing 'name' parameter.", $targetName);
                        }

                        break;
                    case 'copy':
                        if (!isset($task['params']['source']) || !isset($task['params']['dest'])) {
                            $errors[] = sprintf("Copy task in target '%s' missing 'source' or 'dest'.", $targetName);
                        }

                        break;
                    case 'exec':
                        if (!isset($task['params']['command'])) {
                            $errors[] = sprintf("Exec task in target '%s' missing 'command'.", $targetName);
                        }

                        break;
                    case 'echo':
                        if (!isset($task['params']['message'])) {
                            $errors[] = sprintf("Echo task in target '%s' missing 'message'.", $targetName);
                        }

                        break;
                    case 'archive':
                        if (!isset($task['params']['source']) || !isset($task['params']['output'])) {
                            $errors[] = sprintf("Archive task in target '%s' missing required parameters.", $targetName);
                        }

                        if (isset($task['params']['compression'])) {
                            $compression = $task['params']['compression'];
                            if (!in_array($compression, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE])) {
                                $errors[] = sprintf("Invalid compression value '%s' for archive task.", $compression);
                            }
                        }

                        break;
                    default:
                        $errors[] = sprintf("Unknown task type '%s' in target '%s'.", $task['type'], $targetName);
                }
            }
        }

        return $errors;
    }
}
