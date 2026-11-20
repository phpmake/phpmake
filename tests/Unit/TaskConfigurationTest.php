<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Task\TaskConfiguration;
use PHPUnit\Framework\TestCase;

/** Tests the typed boundary for decoded task configuration entries. */
final class TaskConfigurationTest extends TestCase
{
    /** Converts a valid task entry into typed identifier and parameter values. */
    public function testFromArrayCreatesTypedConfiguration(): void
    {
        // Arrange
        $input = [
            'type' => 'echo',
            'params' => ['message' => 'hello'],
        ];

        // Act
        $configuration = TaskConfiguration::fromArray($input);

        // Assert
        self::assertSame('echo', $configuration->getType());
        self::assertSame(['message' => 'hello'], $configuration->getParams());
    }

    /** Rejects task entries without a non-empty string type. */
    public function testFromArrayRejectsMissingTaskType(): void
    {
        // Arrange
        $input = ['params' => []];

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        TaskConfiguration::fromArray($input);
    }

    /** Rejects task entries whose params member is not an array. */
    public function testFromArrayRejectsNonArrayParameters(): void
    {
        // Arrange
        $input = ['type' => 'echo', 'params' => 'invalid'];

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        TaskConfiguration::fromArray($input);
    }
}
