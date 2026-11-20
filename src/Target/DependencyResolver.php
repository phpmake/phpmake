<?php

namespace PhpMake\Target;

use PhpMake\Build\BuildConfiguration;

/** Resolves target dependencies into a stable, duplicate-free execution order. */
final class DependencyResolver
{
    /**
     * Resolves dependencies for a target and keeps traversal state local to this call.
     *
     * @return list<string>
     */
    public function resolve(BuildConfiguration $config, string $target): array
    {
        $visiting = [];
        $resolved = [];
        $order = [];

        $this->visit($config, $target, $visiting, $resolved, $order);

        return $order;
    }

    /**
     * Performs depth-first traversal with O(1) membership checks.
     *
     * @param array<string, true> $visiting
     * @param array<string, true> $resolved
     * @param list<string> $order
     */
    private function visit(
        BuildConfiguration $config,
        string $target,
        array &$visiting,
        array &$resolved,
        array &$order
    ): void {
        if (isset($resolved[$target])) {
            return;
        }

        if (isset($visiting[$target])) {
            throw new \RuntimeException(sprintf("Circular dependency detected in target '%s'.", $target));
        }

        $targets = $config->getTargets();
        if (!array_key_exists($target, $targets)) {
            throw new \InvalidArgumentException(sprintf("Target '%s' does not exist.", $target));
        }

        $dependencies = $targets[$target]['depends'] ?? [];
        if (!is_array($dependencies)) {
            throw new \InvalidArgumentException(sprintf("Dependencies for target '%s' must be an array.", $target));
        }

        $visiting[$target] = true;
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || $dependency === '') {
                throw new \InvalidArgumentException(sprintf("Target '%s' has an invalid dependency.", $target));
            }

            $this->visit($config, $dependency, $visiting, $resolved, $order);
        }

        unset($visiting[$target]);

        $resolved[$target] = true;
        $order[] = $target;
    }
}
