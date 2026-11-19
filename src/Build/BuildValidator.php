<?php

namespace PhpMake\Build;

/** Validates build configuration fields, targets, dependencies, and task parameters. */
final class BuildValidator
{
    /** Returns validation errors found in a build configuration. */
    public function validate(array $config): array
    {
        $errors = [];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, ['name', 'description', 'version', 'targets', 'default_target'], true)) {
                $errors[] = sprintf("Unknown top-level property '%s'.", (string) $key);
            }
        }

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
            // json_decode(..., true) converts numeric object keys to integers.
            // The JSON schema permits these names, so validate their string form.
            $targetName = (string) $targetName;
            if (trim($targetName) === '') {
                $errors[] = 'Target names must be non-empty strings.';
            }

            if (!is_array($target)) {
                $errors[] = sprintf("Target '%s' must be an object.", $targetName);
                continue;
            }

            foreach (array_keys($target) as $key) {
                if (!in_array($key, ['description', 'depends', 'tasks'], true)) {
                    $errors[] = sprintf("Unknown property '%s' in target '%s'.", (string) $key, $targetName);
                }
            }

            if (array_key_exists('description', $target) && !is_string($target['description'])) {
                $errors[] = sprintf("Target '%s' description must be a string.", $targetName);
            }

            $depends = array_key_exists('depends', $target) ? $target['depends'] : [];
            if (!is_array($depends)) {
                $errors[] = sprintf("Target '%s' dependencies must be an array.", $targetName);
            } else {
                foreach ($depends as $dependency) {
                    if (!is_string($dependency) || !array_key_exists($dependency, $targets)) {
                        $errors[] = sprintf(
                            "Target '%s' depends on missing or invalid target '%s'.",
                            $targetName,
                            is_scalar($dependency) ? (string) $dependency : '[invalid]'
                        );
                    }
                }
            }

            if (!isset($target['tasks']) || !is_array($target['tasks'])) {
                $errors[] = sprintf("Target '%s' has no valid 'tasks' array.", $targetName);
                continue;
            }

            foreach ($target['tasks'] as $index => $task) {
                if (!is_array($task)) {
                    $errors[] = sprintf("Task %s in target '%s' must be an object.", (string) $index, $targetName);
                    continue;
                }

                foreach (array_keys($task) as $key) {
                    if (!in_array($key, ['type', 'params'], true)) {
                        $errors[] = sprintf("Unknown property '%s' in task %s of target '%s'.", (string) $key, (string) $index, $targetName);
                    }
                }

                $type = $task['type'] ?? null;
                if (!is_string($type) || $type === '') {
                    $errors[] = sprintf("Task %s in target '%s' has no valid 'type'.", (string) $index, $targetName);
                    continue;
                }

                if (!array_key_exists('params', $task) || !is_array($task['params'])) {
                    $errors[] = sprintf("Task %s in target '%s' has no valid 'params' object.", (string) $index, $targetName);
                    continue;
                }

                $params = $task['params'];

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
                    $errors[] = sprintf("Unknown task type '%s' in target '%s'.", $type, $targetName);
                    continue;
                }

                $allowedParams = match ($type) {
                    'delete' => ['path'],
                    'create_directory' => ['name'],
                    'copy' => ['source', 'dest'],
                    'exec' => ['command', 'args', 'timeout'],
                    'echo' => ['message'],
                    'archive' => ['source', 'output'],
                };
                foreach (array_keys($params) as $key) {
                    if (!in_array($key, $allowedParams, true)) {
                        $errors[] = sprintf("Unknown parameter '%s' for %s task in target '%s'.", (string) $key, $type, $targetName);
                    }
                }

                foreach ($required as $key) {
                    $allowEmptyEcho = $type === 'echo' && $key === 'message';
                    if (!array_key_exists($key, $params) || !is_string($params[$key]) || (!$allowEmptyEcho && trim($params[$key]) === '')) {
                        $errors[] = sprintf("%s task in target '%s' requires non-empty string '%s'.", ucfirst($type), $targetName, $key);
                    }
                }

                if ($type === 'exec') {
                    if (array_key_exists('args', $params) && (!is_array($params['args']) || array_filter($params['args'], static fn($value): bool => !is_scalar($value)) !== [])) {
                        $errors[] = sprintf("Exec task in target '%s' has invalid 'args' (expected scalar array).", $targetName);
                    }

                    if (array_key_exists('timeout', $params) && (
                        (!is_int($params['timeout']) && !is_float($params['timeout']))
                        || !is_finite((float) $params['timeout'])
                        || (float) $params['timeout'] <= 0
                        || (float) $params['timeout'] > 86400
                    )) {
                        $errors[] = sprintf("Exec task in target '%s' has invalid 'timeout' (expected a number greater than 0 and at most 86400).", $targetName);
                    }
                }
            }
        }

        return $errors;
    }
}
