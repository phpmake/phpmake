<?php

declare(strict_types=1);

namespace PhpMake\Task;

use PhpMake\Task\Types\CopyTask;
use PhpMake\Task\Types\CreateDirectoryTask;
use PhpMake\Task\Types\DeleteTask;
use PhpMake\Task\Types\EchoTask;
use PhpMake\Task\Types\ExecTask;
use PhpMake\Task\Types\ZipTask;

/**
 * Canonical registry for task identifiers, constructors, and parameter contracts.
 *
 * @phpstan-type TaskDefinition array{
 *     class: class-string<BaseTask>,
 *     required: list<string>,
 *     allowed: list<string>
 * }
 */
final class TaskDefinitionRegistry
{
    /**
     * @var array<string, TaskDefinition>
     */
    private const DEFINITIONS = [
        'delete' => [
            'class' => DeleteTask::class,
            'required' => ['path'],
            'allowed' => ['path'],
        ],
        'create_directory' => [
            'class' => CreateDirectoryTask::class,
            'required' => ['name'],
            'allowed' => ['name'],
        ],
        'copy' => [
            'class' => CopyTask::class,
            'required' => ['source', 'dest'],
            'allowed' => ['source', 'dest'],
        ],
        'exec' => [
            'class' => ExecTask::class,
            'required' => ['command'],
            'allowed' => ['command', 'args', 'timeout'],
        ],
        'echo' => [
            'class' => EchoTask::class,
            'required' => ['message'],
            'allowed' => ['message'],
        ],
        'archive' => [
            'class' => ZipTask::class,
            'required' => ['source', 'output'],
            'allowed' => ['source', 'output'],
        ],
    ];

    /** @return array<string, TaskDefinition> */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }

    /** Returns the definition for a supported task, or null for an unknown type. */
    public static function find(string $type): ?array
    {
        return self::DEFINITIONS[$type] ?? null;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
