<?php

namespace PhpMake\Target;

use PhpMake\Build\BuildConfiguration;

/**
 * DependencyResolver Class
 *
 * Resolves dependencies between build targets to determine
 * the correct execution order.
 *
 * @package    PHPMake
 * @subpackage Target
 */
final class DependencyResolver
{
    /**
     * @var array Tracks visited targets to prevent circular dependencies.
     */
    private array $visited = [];

    /**
     * Resolve dependencies for the given target.
     *
     * Determines correct execution order based on dependencies.
     *
     * @param BuildConfiguration $config Build configuration object.
     * @param string             $target Target whose dependencies need resolution.
     *
     * @return array Ordered list of targets for execution.
     */
    public function resolve(BuildConfiguration $config, string $target): array
    {
        $this->visited = []; // Reset for each resolve call.
        return $this->resolveRecursive($config, $target);
    }

    /**
     * Recursively resolve dependencies.
     *
     * Traverses dependency tree and ensures circular dependencies are detected.
     *
     * @param BuildConfiguration $config Build configuration object.
     * @param string             $target Target whose dependencies are being resolved.
     *
     * @return array Ordered list of dependencies including target.
     *
     * @throws \Exception If a circular dependency is detected.
     */
    private function resolveRecursive(BuildConfiguration $config, string $target): array
    {
        if (in_array($target, $this->visited)) {
            throw new \Exception(sprintf("Circular dependency detected in target '%s'.", $target));
        }

        $this->visited[] = $target;
        $targets = $config->getTargets();
        $targetConfig = $targets[$target] ?? [];
        $depends = $targetConfig['depends'] ?? [];
        $order = [];

        foreach ($depends as $dep) {
            $order = array_merge($order, $this->resolveRecursive($config, $dep));
        }

        $order[] = $target;
        array_pop($this->visited); // Backtrack after resolving dependencies.
        return array_unique($order);
    }
}
