<?php

namespace PhpMake\Build;

/** Validates build configuration fields, targets, dependencies, and task parameters. */
final class BuildValidator
{
    /** Returns validation errors found in a build configuration. */
    public function validate(array $config): array
    {
        $errors = [];
        foreach (['name', 'description', 'version', 'targets', 'default_target'] as $field) {
            if (!array_key_exists($field, $config)) {
                $errors[] = sprintf('Missing required field: %s.', $field);
            }
        }

        foreach (['name', 'description', 'version'] as $field) {
            if (!isset($config[$field]) || !is_string($config[$field]) || trim($config[$field]) === '') {
                $errors[] = sprintf("Field '%s' must be a non-empty string.", $field);
            }
        }

        if (!is_array($config['targets'] ?? null)) {
            $errors[] = "Field 'targets' must be an object/array.";
            return $errors;
        }

        $targets = $config['targets'];
        $default = $config['default_target'] ?? null;
        if (!is_string($default) || trim($default) === '' || !array_key_exists($default, $targets)) {
            $errors[] = sprintf(
                "Default target '%s' does not exist or is invalid.",
                is_scalar($default) ? (string) $default : '[invalid]'
            );
        }

        foreach ($targets as $targetName => $target) {
            if (!is_array($target)) {
                $errors[] = sprintf("Target '%s' must be an object.", (string) $targetName);
                continue;
            }

            $depends = $target['depends'] ?? [];
            if (!is_array($depends)) {
                $errors[] = sprintf("Target '%s' dependencies must be an array.", (string) $targetName);
            } else {
                foreach ($depends as $dependency) {
                    if (!is_string($dependency) || !array_key_exists($dependency, $targets)) {
                        $errors[] = sprintf(
                            "Target '%s' depends on missing or invalid target '%s'.",
                            (string) $targetName,
                            is_scalar($dependency) ? (string) $dependency : '[invalid]'
                        );
                    }
                }
            }

            if (!isset($target['tasks']) || !is_array($target['tasks'])) {
                $errors[] = sprintf("Target '%s' has no valid 'tasks' array.", (string) $targetName);
                continue;
            }

            foreach ($target['tasks'] as $index => $task) {
                if (!is_array($task)) {
                    $errors[] = sprintf("Task %s in target '%s' must be an object.", (string) $index, (string) $targetName);
                    continue;
                }

                $type = $task['type'] ?? null;
                if (!is_string($type) || $type === '') {
                    $errors[] = sprintf("Task %s in target '%s' has no valid 'type'.", (string) $index, (string) $targetName);
                    continue;
                }

                $params = $task['params'] ?? [];
                if (!is_array($params)) {
                    $errors[] = sprintf("Task %s in target '%s' has invalid 'params'.", (string) $index, (string) $targetName);
                    continue;
                }

                $required = match ($type) {
                    'delete' => ['path'],
                    'create_directory' => ['name'],
                    'copy' => ['source', 'dest'],
                    'exec' => ['command'],
                    'echo' => ['message'],
                    'archive' => ['source', 'output'],
                    default => null,
                };

                if ($required === null) {
                    $errors[] = sprintf("Unknown task type '%s' in target '%s'.", $type, (string) $targetName);
                    continue;
                }

                foreach ($required as $key) {
                    if (!isset($params[$key]) || !is_string($params[$key]) || trim($params[$key]) === '') {
                        $errors[] = sprintf("%s task in target '%s' requires non-empty string '%s'.", ucfirst($type), (string) $targetName, $key);
                    }
                }

                if ($type === 'exec') {
                    if (isset($params['args']) && (!is_array($params['args']) || array_filter($params['args'], static fn($value) => !is_scalar($value)))) {
                        $errors[] = sprintf("Exec task in target '%s' has invalid 'args' (expected scalar array).", (string) $targetName);
                    }

                    if (isset($params['timeout']) && (
                        !is_numeric($params['timeout'])
                        || (float) $params['timeout'] <= 0
                        || (float) $params['timeout'] > 86400
                    )) {
                        $errors[] = sprintf("Exec task in target '%s' has invalid 'timeout' (expected a number greater than 0 and at most 86400).", (string) $targetName);
                    }
                }
            }
        }

        return $errors;
    }
}
