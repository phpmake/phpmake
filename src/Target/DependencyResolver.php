<?php

namespace PhpMake\Target;

use PhpMake\Build\BuildConfiguration;

/** Resolves target dependencies into a stable, duplicate-free execution order. */
final class DependencyResolver
{
    /** @var array<string, true> Targets currently on the DFS recursion stack. */
    private array $visiting = [];

    /** @var array<string, true> Targets already appended to the execution order. */
    private array $resolved = [];

    /** @var list<string> Resolved targets in dependency-first order. */
    private array $order = [];

    /** Resolves dependencies for a target and resets state for every invocation. */
    public function resolve(BuildConfiguration $config, string $target): array
    {
        $this->visiting = [];
        $this->resolved = [];
        $this->order = [];
        $this->visit($config, $target);

        return $this->order;
    }

    /** Performs depth-first traversal with O(1) membership checks. */
    private function visit(BuildConfiguration $config, string $target): void
    {
        if (isset($this->resolved[$target])) {
            return;
        }

        if (isset($this->visiting[$target])) {
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

        $this->visiting[$target] = true;
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || $dependency === '') {
                throw new \InvalidArgumentException(sprintf("Target '%s' has an invalid dependency.", $target));
            }

            $this->visit($config, $dependency);
        }

        unset($this->visiting[$target]);

        $this->resolved[$target] = true;
        $this->order[] = $target;
    }
}
