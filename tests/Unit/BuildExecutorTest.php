<?php

declare(strict_types=1);

namespace PhpMake\Tests\Unit;

use PhpMake\Build\BuildConfiguration;
use PhpMake\Build\BuildExecutor;
use PhpMake\Target\DependencyResolver;
use PhpMake\Task\TaskExecutor;
use PhpMake\Task\TaskFactory;
use PhpMake\Utilities\Logger;
use PHPUnit\Framework\TestCase;

/** Tests build orchestration and target selection failures. */
final class BuildExecutorTest extends TestCase
{
    /** Executing an undefined target throws a descriptive exception. */
    public function testExecuteRejectsMissingTarget(): void
    {
        // Arrange
        $logger = new Logger(false, true, true);
        $config = new BuildConfiguration(
            'BuildExecutorTest',
            'Missing target',
            '1.0.0',
            ['build' => ['tasks' => []]],
            'build'
        );
        $executor = new BuildExecutor(
            new TaskFactory($logger),
            new DependencyResolver(),
            new TaskExecutor()
        );

        // Assert
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage("Target 'missing' not found.");
        // Act
        $executor->execute($config, 'missing', $logger);
    }

    /** Explicit target name "0" is selected instead of falling back to the default. */
    public function testExecuteSelectsNumericZeroTarget(): void
    {
        // Arrange
        $logger = new Logger(false, true, true);
        $config = new BuildConfiguration(
            'NumericTarget',
            'Target zero must be selectable',
            '1.0.0',
            [
                '0' => ['tasks' => []],
                'default' => ['tasks' => [['type' => 'unknown', 'params' => []]]],
            ],
            'default'
        );
        $executor = new BuildExecutor(
            new TaskFactory($logger),
            new DependencyResolver(),
            new TaskExecutor()
        );

        // Act
        $exitCode = $executor->execute($config, '0', $logger);

        // Assert
        self::assertSame(0, $exitCode);
    }

    /** Uses the configured default target when no target is supplied. */
    public function testExecuteUsesDefaultTargetWhenTargetIsNull(): void
    {
        // Arrange
        $logger = new Logger(false, true, true);
        $config = new BuildConfiguration(
            'DefaultTarget',
            'Default target selection',
            '1.0.0',
            ['default' => ['tasks' => [['type' => 'echo', 'params' => ['message' => 'default']]]]],
            'default'
        );
        $executor = new BuildExecutor(
            new TaskFactory($logger),
            new DependencyResolver(),
            new TaskExecutor()
        );

        // Act
        $exitCode = $executor->execute($config, null, $logger);

        // Assert
        self::assertSame(0, $exitCode);
    }
}
