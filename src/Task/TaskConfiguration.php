<?php

declare(strict_types=1);

namespace PhpMake\Task;

/** Typed boundary for one task entry loaded from build configuration. */
final class TaskConfiguration
{
    /** @param array<string, mixed> $params */
    public function __construct(private string $type, private array $params) {}

    /**
     * Creates a typed task configuration from the decoded JSON representation.
     *
     * @param array<string, mixed> $taskConfig
     */
    public static function fromArray(array $taskConfig): self
    {
        $type = $taskConfig['type'] ?? null;
        $params = $taskConfig['params'] ?? [];

        if (!is_string($type) || $type === '') {
            throw new \InvalidArgumentException("Task configuration requires a non-empty string 'type'.");
        }

        if (!is_array($params)) {
            throw new \InvalidArgumentException(sprintf("Parameters for task '%s' must be an array.", $type));
        }

        /** @var array<string, mixed> $params */
        return new self($type, $params);
    }

    /** Returns the task identifier. */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getParams(): array
    {
        return $this->params;
    }
}
